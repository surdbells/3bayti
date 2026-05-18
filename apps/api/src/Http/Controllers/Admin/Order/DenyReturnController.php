<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\DenyReturnInput;
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
 * POST /v3/admin/returns/{id}/deny
 *
 * Admin denies a pending return request. State transition:
 * STATUS_PENDING → STATUS_DENIED (terminal). admin_notes is required
 * by the entity layer — the customer deserves an explanation.
 *
 * 422 with RETURN_CANNOT_DENY if the request isn't pending.
 */
final class DenyReturnController
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

        $input = $this->validator->parse($request, DenyReturnInput::class);

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }

        try {
            $returnRequest->deny($admin, $input->admin_notes);
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_DENY',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        try {
            $this->notifications->returnDenied($returnRequest->getOrder(), [
                'return_reference' => 'RET-' . ($returnRequest->getId() ?? 0),
                'admin_notes' => $returnRequest->getAdminNotes() ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('return.notification.denied_failed', [
                'return_id' => $returnRequest->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok([
            'data' => $this->serializer->adminShape($returnRequest),
        ]);
    }
}
