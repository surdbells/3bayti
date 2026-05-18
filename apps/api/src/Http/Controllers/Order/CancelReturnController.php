<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/returns/{id}/cancel
 *
 * Customer withdraws a return request that hasn't been decided
 * yet. Only allowed from STATUS_PENDING per the state machine —
 * the aggregate root rejects illegal transitions with
 * DomainException, which we translate to 422.
 *
 * Authorization: customer must own the underlying order. 404 on
 * cross-user.
 *
 * Response: 200 with the updated customer-shape (status: cancelled).
 */
final class CancelReturnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
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

        $returnId = (int) ($args['id'] ?? 0);
        if ($returnId <= 0) {
            throw HttpException::notFound('Return request not found.');
        }

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }
        if ($returnRequest->getCustomer()->getId() !== $user->getId()) {
            throw HttpException::notFound('Return request not found.');
        }

        try {
            $returnRequest->cancelByCustomer();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_CANCEL',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        return $this->ok([
            'data' => $this->serializer->customerShape($returnRequest),
        ]);
    }
}
