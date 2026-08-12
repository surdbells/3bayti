<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendors/{id}
 *
 * Single-vendor detail for the admin "Manage store" screen. Returns the
 * owner user's identity, the store profile, the onboarding
 * banking / tax / trade-license fields, and the KYC document URLs, shaped
 * as the flat legacy `store` object the portal binds
 * ({@see VendorSerializer::manageShape}).
 *
 * Without this route the portal's GET /admin/vendors/:id 404s, leaving
 * the store object at its initialised defaults — which is why the
 * Information tab rendered empty and the setup-progress meter was stuck
 * at 7% (two default `false` booleans out of ~30 fields) for every
 * vendor.
 *
 * Authorization: admin-only (group middleware in routes.php enforces the
 * AdminAuthMiddleware -> AuthMiddleware stack). Audit ACTION_VIEWED is
 * emitted on every successful call with the vendor as subject.
 */
final class GetAdminVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
        private readonly AuditEmitter $audit,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $vendorId = (int) ($args['id'] ?? 0);
        if ($vendorId <= 0) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $vendor,
            context: ['context' => 'admin_vendor_detail'],
        );

        return $this->ok(['data' => $this->serializer->manageShape($vendor)]);
    }
}
