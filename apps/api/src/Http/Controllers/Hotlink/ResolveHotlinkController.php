<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Hotlink;

use Bayti\Api\Domain\Hotlink\Hotlink;
use Bayti\Api\Domain\Hotlink\HotlinkClickLogger;
use Bayti\Api\Domain\Hotlink\HotlinkRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/hotlinks/{code}?sid={sessionId}   (OptionalAuthMiddleware)
 *
 * Resolve a short code to its target and RECORD the click (both the
 * denormalized counter and the ledger row that drives conversion
 * attribution). Returns { target_type, target_slug } so the web /s/:code
 * route can navigate to /stores/{slug} or /styles/{slug}. Never 401s;
 * logged-out clicks are counted (user_id null, session_id tag) for reach.
 */
final class ResolveHotlinkController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly HotlinkClickLogger $clicks,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            throw HttpException::notFound('Link not found.');
        }

        /** @var HotlinkRepository $repo */
        $repo = $this->em->getRepository(Hotlink::class);
        $hotlink = $repo->findByCode($code);
        if ($hotlink === null) {
            throw HttpException::notFound('Link not found.');
        }

        // Count the click (atomic denormalized bump) + write the attribution
        // ledger row. Both writes live in the logger and are guarded/swallowed
        // so click tracking can NEVER break the resolve/redirect — no unguarded
        // flush on the hot path, and no request-length lock on the shared row.
        $viewer = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $viewer instanceof User ? $viewer->getId() : null;
        $sid = $request->getQueryParams()['sid'] ?? null;
        $sessionId = is_string($sid) && $sid !== '' ? $sid : null;
        $hotlinkId = $hotlink->getId();
        if ($hotlinkId !== null) {
            $this->clicks->recordClick($hotlinkId, $userId, $sessionId);
        }

        return $this->ok([
            'data' => [
                'target_type' => $hotlink->getTargetType(),
                'target_slug' => $hotlink->getTargetSlug(),
            ],
        ]);
    }
}
