<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\ApproveReturnInput;
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
 * POST /v3/admin/returns/{id}/approve
 *
 * Admin approves a pending return request. State transition:
 * STATUS_PENDING → STATUS_APPROVED. admin_notes is optional.
 *
 * 422 with RETURN_CANNOT_APPROVE if the request isn't pending.
 */
final class ApproveReturnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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

        $input = $this->validator->parse($request, ApproveReturnInput::class);

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }

        try {
            $returnRequest->approve($admin, $input->admin_notes);
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_APPROVE',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        return $this->ok([
            'data' => $this->serializer->adminShape($returnRequest),
        ]);
    }
}
