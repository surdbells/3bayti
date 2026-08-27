<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\DisputeSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/disputes/{id}
 *
 * Single dispute detail. Includes raw_event payload for forensic
 * review.
 *
 * Q5=A: emits ACTION_VIEWED audit entry. The audit_log table grows
 * by one row per detail view; retention policy of 1 year applies
 * (see m3.1.7 closure runbook).
 */
final class GetDisputeController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly DisputeSerializer $serializer,
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

        $disputeId = (int) ($args['id'] ?? 0);
        if ($disputeId <= 0) {
            throw HttpException::notFound('Dispute not found.');
        }

        $dispute = $this->em->find(OrderDispute::class, $disputeId);
        if ($dispute === null) {
            throw HttpException::notFound('Dispute not found.');
        }

        $shape = $this->serializer->shape($dispute);
        // Include raw_event on detail view (not in list shape, too big
        // for paginated responses)
        $shape['raw_event'] = $dispute->getRawEvent();

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $dispute,
            context: ['context' => 'admin_dispute_detail'],
        );

        return $this->ok(['dispute' => $shape]);
    }
}
