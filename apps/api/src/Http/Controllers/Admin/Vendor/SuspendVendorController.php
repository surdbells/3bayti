<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\VendorTransitionInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/vendors/{id}/suspend
 *
 * Suspend a vendor: transitions the lifecycle status from approved
 * → suspended. Hides them from public surfaces + blocks future
 * vendor-endpoint access via VendorAuthMiddleware (if the user has
 * no other approved stores).
 *
 * Body:
 *   { "reason"?: string }
 *
 * Reason is recommended (not enforced), operationally important
 * for the audit trail and any future vendor-facing communication
 * about the suspension.
 *
 * Side effects:
 *   - vendor.status = 'suspended'
 *   - vendor.status_changed_at = now()
 *   - vendor.status_reason = body.reason (or null)
 *   - vendor.is_store_approved is NOT toggled, the vendor was
 *     historically approved; suspension is the more-recent
 *     operational state (Q-LegacyFlags=A invariant)
 *
 * Returns:
 *   200 with updated vendor in adminShape
 *
 * Errors:
 *   - 404 if vendor not found
 *   - 422 if vendor is not in approved state (must approve first;
 *     transition guard in entity)
 *   - 403 (from AdminAuthMiddleware) if caller is not admin
 *
 * Audit (Q-Audit=A):
 *   Records ACTION_UPDATED with before/after snapshots capturing
 *   the status transition + reason.
 */
final class SuspendVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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
     * @param array<string, string> $args Route placeholder values from Slim
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->find((int) $idRaw);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $input = $this->validator->parse($request, VendorTransitionInput::class);

        $before = $this->audit->snapshot($vendor);

        try {
            $vendor->suspend($input->reason);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation([
                'status' => [$e->getMessage()],
            ]);
        }

        // Keep the legacy users.is_store_active column in sync. Suspension
        // does NOT change is_store_approved (the vendor was historically
        // approved; suspension is the more-recent operational state).
        // Guard for a null owner (old/admin-created vendors).
        $owner = $vendor->getOwnerUser();
        if ($owner !== null) {
            $owner->setStoreState(active: false);
        }

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $vendor,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($vendor),
        );

        return $this->ok([
            'vendor' => $this->serializer->adminShape($vendor),
        ]);
    }
}
