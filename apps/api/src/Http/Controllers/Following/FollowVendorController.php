<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Following;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/following/{vendorId}, follow a vendor.
 *
 * Idempotent: if already following, this is a no-op success (200), not a
 * 409. New follow → 201. Both return the followed vendor in the same
 * { data: <vendor publicShape> } shape so the client updates uniformly.
 *
 * The vendor must exist and be active (404 otherwise), you can't follow
 * a delisted/nonexistent store.
 */
final class FollowVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $vendorId = (int) ($args['vendorId'] ?? 0);
        $vendor = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;
        if (!$vendor instanceof Vendor || !$vendor->isActive()) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorFollowRepository $repo */
        $repo = $this->em->getRepository(VendorFollow::class);

        // Serialize the vendor up-front, while the EM is guaranteed open — a
        // failed flush below closes the EM, so we must not serialize after it.
        $envelope = PaginatedEnvelope::single($this->serializer->publicShape($vendor));

        // Idempotent: already following → no-op 200.
        $existing = $repo->findOneForUserAndVendor($user, $vendor);
        if ($existing !== null) {
            return $this->ok($envelope);
        }

        try {
            $repo->save(new VendorFollow($user, $vendor));
        } catch (UniqueConstraintViolationException) {
            // Concurrent double-tap: another request created the follow between
            // our existence check and this insert. The unique index is the race
            // backstop (uq_vendor_follow_user_vendor); treat the collision as
            // the idempotent success it is — the user now follows the vendor —
            // rather than surfacing a 500 (PHP-23).
            return $this->ok($envelope);
        }

        return $this->created($envelope);
    }
}
