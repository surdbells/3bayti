<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Shipping;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Shipping\ShippingProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/shipping/pickup-locations
 *
 * The pickup/sender locations registered in the OTO portal, so an admin can pick
 * a vendor's sender from a searchable dropdown (by code) instead of typing it.
 * Empty list when the courier provider is off. Gated by vendors.edit.
 */
final class ListPickupLocationsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ShippingProviderInterface $provider,
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

        return $this->ok([
            'enabled' => $this->provider->isEnabled(),
            'locations' => $this->provider->listPickupLocations(),
        ]);
    }
}
