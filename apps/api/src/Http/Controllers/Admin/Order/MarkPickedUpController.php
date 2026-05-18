<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

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
 * POST /v3/admin/returns/{id}/mark-picked-up
 *
 * Admin marks the returned goods as physically picked up from the
 * customer by ops. State transition: STATUS_APPROVED → STATUS_PICKED_UP.
 *
 * 422 with RETURN_CANNOT_MARK_PICKED_UP if the request isn't approved.
 */
final class MarkPickedUpController
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

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }

        try {
            $returnRequest->markPickedUp();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_MARK_PICKED_UP',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        return $this->ok([
            'data' => $this->serializer->adminShape($returnRequest),
        ]);
    }
}
