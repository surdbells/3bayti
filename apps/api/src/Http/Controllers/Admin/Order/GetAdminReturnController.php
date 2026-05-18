<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Order\ReturnRefundCalculator;
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
 * GET /v3/admin/returns/{id}
 *
 * Admin-shape detail for a single return request. Includes the
 * computed suggested_refund_amount so the operator UI can pre-fill
 * the refund form.
 *
 * The suggested amount is computed via ReturnRefundCalculator using
 * the pro-rated discount formula from X.18-C; admin can override
 * when actually recording the refund.
 */
final class GetAdminReturnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
        private readonly ReturnRefundCalculator $refundCalculator,
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

        // Compute the suggested refund amount for the operator UI.
        // This is purely informational — admin can override in the
        // record-refund DTO.
        $items = iterator_to_array($returnRequest->getItems());
        $suggested = $this->refundCalculator->compute(
            $returnRequest->getOrder(),
            array_values($items),
        );

        $data = $this->serializer->adminShape($returnRequest);
        $data['suggested_refund_amount'] = $suggested;

        return $this->ok(['data' => $data]);
    }
}
