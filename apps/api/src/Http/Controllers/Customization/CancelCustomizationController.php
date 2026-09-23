<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CustomizationRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/customization-requests/{id}/cancel
 *
 * Customer withdraws their request before committing to pay — allowed
 * from 'pending' or 'quoted' (terminal 'cancelled').
 */
final class CancelCustomizationController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CustomizationRequestSerializer $serializer,
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Customization request not found.');
        }

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        $customization = $repo->findById($id);
        if ($customization === null || $customization->getCustomer()->getId() !== $user->getId()) {
            throw HttpException::notFound('Customization request not found.');
        }

        try {
            $customization->cancelByCustomer();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'CUSTOMIZATION_CANNOT_CANCEL',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($customization);

        return $this->ok([
            'data' => $this->serializer->customerShape($customization),
        ]);
    }
}
