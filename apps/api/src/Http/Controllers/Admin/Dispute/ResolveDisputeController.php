<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Dispute\Dto\ResolveDisputeInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\DisputeSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * PATCH /v3/admin/disputes/{id}
 *
 * Advance a dispute through the lifecycle:
 *   open → in_review → resolved_won / resolved_lost / withdrawn
 *
 * Body: { status: 'in_review' | 'resolved_won' | 'resolved_lost' | 'withdrawn',
 *         resolution_note?: '...' }
 *
 * resolution_note is REQUIRED for terminal statuses (resolved_*,
 * withdrawn), admin accountability. Optional for in_review.
 *
 * Q5=A: emits ACTION_OVERRIDDEN with full before/after diff.
 *
 * Idempotency
 * -----------
 * Re-applying the same status to a dispute already in that state is
 * a no-op for in_review. For terminal statuses, re-applying throws
 * (handled by OrderDispute::markResolved), preventing accidental
 * "re-resolution" with a different note.
 */
final class ResolveDisputeController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly DisputeSerializer $serializer,
        private readonly AuditEmitter $audit,
        private readonly LoggerInterface $logger,
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

        $disputeId = (int) ($args['id'] ?? 0);
        if ($disputeId <= 0) {
            throw HttpException::notFound('Dispute not found.');
        }

        $input = $this->validator->parse($request, ResolveDisputeInput::class);
        $status = $input->status;
        if ($status === null) {
            // Defensive: validator should have caught this.
            throw new HttpException(
                status: 422,
                errorCode: 'invalid_status',
                publicMessage: 'status is required.',
            );
        }

        $dispute = $this->em->find(OrderDispute::class, $disputeId);
        if ($dispute === null) {
            throw HttpException::notFound('Dispute not found.');
        }

        // resolution_note required for terminal transitions
        if (in_array($status, OrderDispute::TERMINAL_STATUSES, true)) {
            if ($input->resolution_note === null || trim($input->resolution_note) === '') {
                throw new HttpException(
                    status: 422,
                    errorCode: 'resolution_note_required',
                    publicMessage: 'resolution_note is required when resolving a dispute.',
                );
            }
        }

        $previousStatus = $dispute->getStatus();

        try {
            if ($status === OrderDispute::STATUS_IN_REVIEW) {
                $dispute->markInReview();
            } else {
                $dispute->markResolved(
                    resolutionStatus: $status,
                    resolutionNote: $input->resolution_note ?? '',
                    resolver: $user,
                );
            }
        } catch (\DomainException $e) {
            // Dispute was already terminal, re-resolution attempt
            throw new HttpException(
                status: 422,
                errorCode: 'dispute_not_mutable',
                publicMessage: $e->getMessage(),
                details: ['current_status' => $dispute->getStatus()],
            );
        }

        $this->em->flush();

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $dispute,
            changes: [
                'before' => ['status' => $previousStatus],
                'after' => ['status' => $dispute->getStatus()],
                'resolution_note' => $input->resolution_note,
                'context' => 'admin_dispute_resolved',
            ],
        );

        $this->logger->info('admin.dispute.transitioned', [
            'dispute_id' => $dispute->getId(),
            'previous_status' => $previousStatus,
            'new_status' => $dispute->getStatus(),
            'actor_user_id' => $user->getId(),
        ]);

        return $this->ok(['dispute' => $this->serializer->shape($dispute)]);
    }
}
