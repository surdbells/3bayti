<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderReturnRefund;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\RecordReturnRefundInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/returns/{id}/record-refund
 *
 * Admin records the manual refund payment for a return that has
 * reached STATUS_DELIVERED_TO_VENDOR. State transition:
 * STATUS_DELIVERED_TO_VENDOR → STATUS_REFUNDED (terminal).
 *
 * Per Q-Refund locked: refunds are processed OFF the Noon API
 *, ops handles the actual money movement (bank transfer, store
 * credit, cash) and records the event here for audit + compliance.
 *
 * 422 paths:
 *   - RETURN_CANNOT_REFUND: not in delivered_to_vendor state
 *   - Body validation: amount / method / reference / notes
 *
 * The OrderReturnRefund entity is constructed with the admin user
 * (recordedByAdmin) for attribution, then the aggregate root's
 * markRefunded() consumes it as the state transition's payload.
 */
final class RecordReturnRefundController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly \Psr\Log\LoggerInterface $logger,
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
        $admin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$admin instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $returnId = (int) ($args['id'] ?? 0);
        if ($returnId <= 0) {
            throw HttpException::notFound('Return request not found.');
        }

        $input = $this->validator->parse($request, RecordReturnRefundInput::class);

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }

        // Build the refund entity. The entity layer validates method
        // membership + money format + currency code; we translate
        // its InvalidArgumentException to 422 with field-level info.
        try {
            $refund = new OrderReturnRefund(
                returnRequest: $returnRequest,
                method: $input->method,
                amount: $input->amount,
                reference: $input->reference,
                notes: $input->notes,
                recordedByAdmin: $admin,
                currency: $input->currency,
            );
        } catch (\InvalidArgumentException $e) {
            // Belt-and-braces, Symfony validator should have caught
            // most of these at the DTO layer, but the entity is
            // stricter (e.g., positive-money via bccomp).
            throw HttpException::validation([
                '_root' => [$e->getMessage()],
            ]);
        }

        try {
            $returnRequest->markRefunded($refund);
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_REFUND',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        try {
            $this->notifications->returnRefunded($returnRequest->getOrder(), [
                'return_reference' => 'RET-' . ($returnRequest->getId() ?? 0),
                'refund_amount' => $refund->getAmount(),
                'refund_currency' => $refund->getCurrency(),
                'refund_method' => $refund->getMethod(),
                'refund_reference' => $refund->getReference(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('return.notification.refunded_failed', [
                'return_id' => $returnRequest->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok([
            'data' => $this->serializer->adminShape($returnRequest),
        ]);
    }
}
