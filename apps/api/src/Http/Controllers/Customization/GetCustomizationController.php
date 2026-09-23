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
 * GET /v3/me/customization-requests/{id}
 *
 * The customer's view of one of their customization requests. 404 (not
 * 403) for a request that isn't theirs.
 */
final class GetCustomizationController
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

        return $this->ok([
            'data' => $this->serializer->customerShape($customization),
        ]);
    }
}
