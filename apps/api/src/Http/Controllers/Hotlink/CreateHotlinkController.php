<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Hotlink;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Hotlink\Hotlink;
use Bayti\Api\Domain\Hotlink\HotlinkRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\HotlinkSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/hotlinks   body: { target_type: 'store'|'style', target_slug }
 *
 * Get-or-create the CANONICAL shareable hotlink for a store or a Style look.
 * The target must exist (an active/approved vendor, or an active style) — we
 * never mint a link to a dead target. Returns the code + ready-to-share URL.
 */
final class CreateHotlinkController
{
    use Responder;

    private const MAX_CODE_ATTEMPTS = 8;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly HotlinkSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = (array) $request->getParsedBody();
        $targetType = is_string($body['target_type'] ?? null) ? trim((string) $body['target_type']) : '';
        $targetSlug = is_string($body['target_slug'] ?? null) ? trim((string) $body['target_slug']) : '';

        if (!in_array($targetType, Hotlink::ALL_TARGETS, true)) {
            throw HttpException::validation(['target_type' => ["target_type must be one of: 'store', 'style'."]]);
        }
        if ($targetSlug === '') {
            throw HttpException::validation(['target_slug' => ['target_slug is required.']]);
        }

        // The target must be real + shareable (anti-dead-link).
        $this->assertTargetExists($targetType, $targetSlug);

        /** @var HotlinkRepository $repo */
        $repo = $this->em->getRepository(Hotlink::class);

        // Canonical: reuse the existing link for this target if there is one.
        $existing = $repo->findByTarget($targetType, $targetSlug);
        if ($existing !== null) {
            return $this->ok(['data' => $this->serializer->shape($existing)]);
        }

        $hotlink = new Hotlink($this->generateCode($repo), $targetType, $targetSlug, $user);
        try {
            $repo->save($hotlink);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // The designed race: a concurrent create for the SAME target tripped
            // uniq_hotlink_target. Return the winning canonical link. (The read
            // path still works after a failed flush closes the EM — findOneBy
            // does not assert an open manager, unlike persist/flush.)
            $winner = $repo->findByTarget($targetType, $targetSlug);
            if ($winner !== null) {
                return $this->ok(['data' => $this->serializer->shape($winner)]);
            }
            // No row for this target ⇒ the violation was a (vanishingly rare)
            // code collision or a transient fault, not a target race. The EM is
            // now closed so we cannot regenerate + re-save in this request;
            // surface a retryable conflict — the client's next POST mints a
            // fresh code.
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_DUPLICATE,
                'Could not create the link right now. Please try again.',
            );
        }

        return $this->created(['data' => $this->serializer->shape($hotlink)]);
    }

    private function assertTargetExists(string $targetType, string $targetSlug): void
    {
        if ($targetType === Hotlink::TARGET_STORE) {
            /** @var VendorRepository $vendors */
            $vendors = $this->em->getRepository(Vendor::class);
            $vendor = $vendors->findBySlug($targetSlug);
            if ($vendor === null || !$vendor->isActive() || !$vendor->isApproved()) {
                throw HttpException::notFound('Store not found.');
            }
            return;
        }

        /** @var StyleRepository $styles */
        $styles = $this->em->getRepository(Style::class);
        if ($styles->findActiveBySlug($targetSlug) === null) {
            throw HttpException::notFound('Look not found.');
        }
    }

    private function generateCode(HotlinkRepository $repo): string
    {
        for ($i = 0; $i < self::MAX_CODE_ATTEMPTS; $i++) {
            // 8 hex chars (~4.3B space); the unique index is the real backstop.
            $code = bin2hex(random_bytes(4));
            if (!$repo->codeExists($code)) {
                return $code;
            }
        }
        // Astronomically unlikely; widen the code rather than fail.
        return bin2hex(random_bytes(8));
    }
}
