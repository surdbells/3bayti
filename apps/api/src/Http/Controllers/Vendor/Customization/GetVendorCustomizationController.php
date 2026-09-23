<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * GET /v3/vendor/customization-requests/{id}
 *
 * The vendor's view of one incoming request. 404 (not 403) if the
 * request isn't for a product this vendor owns.
 */
final class GetVendorCustomizationController
{
    use Responder;
    use ResolvesVendorCustomization;

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
        $customization = $this->resolveForVendor($request, $args, $this->em);

        return $this->ok([
            'data' => $this->serializer->vendorShape($customization),
        ]);
    }
}
