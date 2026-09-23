<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Customization\CustomizationQueryParams;
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
 * GET /v3/vendor/customization-requests
 *
 * The vendor's incoming customization requests (across all stores they
 * own), newest first. Optional ?status= filter + ?limit/?offset.
 */
final class ListVendorCustomizationsController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);

        $filters = CustomizationQueryParams::fromRequest($request->getQueryParams());

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        $result = $repo->findForVendorPaginated($userVendorIds, $filters);

        return $this->ok([
            'data' => $this->serializer->vendorShapeMany($result['items']),
            'meta' => [
                'total' => $result['total'],
                'limit' => $filters['limit'],
                'offset' => $filters['offset'],
            ],
        ]);
    }
}
