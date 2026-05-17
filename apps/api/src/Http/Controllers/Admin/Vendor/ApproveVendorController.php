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
 * POST /v3/admin/vendors/{id}/approve
 *
 * Approve a vendor: transitions the lifecycle status from pending OR
 * suspended → approved. Use suspend → approved (reactivate) only when
 * semantically distinguishing from initial approval matters; for
 * straightforward "make this vendor active" calls, this endpoint
 * works for both transitions.
 *
 * Body:
 *   { "reason"?: string }
 *
 * Side effects:
 *   - vendor.status = 'approved'
 *   - vendor.status_changed_at = now()
 *   - vendor.status_reason = body.reason (or null)
 *   - vendor.is_store_approved = true (kept in sync; Q-LegacyFlags=A)
 *
 * Returns:
 *   200 with the updated vendor in adminShape (includes new status
 *   fields).
 *
 * Errors:
 *   - 404 if vendor not found
 *   - 422 if vendor is already approved (transition guard in entity)
 *   - 403 (from AdminAuthMiddleware) if caller is not admin
 *
 * Audit (Q-Audit=A):
 *   Records ACTION_UPDATED with before/after vendor snapshots. The
 *   snapshot includes status + status_changed_at + status_reason
 *   (M3.2.X.6-C extension to AuditEmitter::snapshotVendor), so the
 *   audit log diff captures the lifecycle transition precisely.
 */
final class ApproveVendorController
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
            $vendor->approve($input->reason);
        } catch (\InvalidArgumentException $e) {
            // Invalid transition (e.g. already approved). The entity
            // is the source of truth on transition validity; we
            // surface its message back to the admin caller.
            throw HttpException::validation([
                'status' => [$e->getMessage()],
            ]);
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
