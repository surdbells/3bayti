<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Shipping;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Shipping\ShippingException;
use Bayti\Api\Shipping\ShippingProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/shipping/pickup-locations
 *
 * Register a NEW pickup/sender location directly in the OTO portal, for when a
 * store's location isn't already there — so the admin can create it inline on
 * the Manage-store screen and immediately map the vendor to it (the response
 * returns the created location for auto-selection in the dropdown).
 *
 * Gated by vendors.edit. 422 when the courier provider is off or a required
 * field is missing/invalid; 502 when OTO rejects the create.
 */
final class CreatePickupLocationController
{
    use Responder;

    /** Required text fields → human label for validation messages. */
    private const REQUIRED = [
        'code' => 'Location code',
        'name' => 'Location name',
        'contact_name' => 'Contact name',
        'contact_email' => 'Contact email',
        'phone' => 'Phone',
        'address' => 'Address',
        'city' => 'City',
    ];

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

        $body = (array) ($request->getParsedBody() ?? []);

        /** @var array<string, list<string>> $errors */
        $errors = [];
        $clean = [];
        foreach (self::REQUIRED as $field => $label) {
            $value = isset($body[$field]) && is_scalar($body[$field]) ? trim((string) $body[$field]) : '';
            if ($value === '') {
                $errors[$field] = ["{$label} is required."];
            }
            $clean[$field] = $value;
        }
        if ($clean['contact_email'] !== '' && !filter_var($clean['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = ['Contact email must be a valid email address.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $country = isset($body['country']) && is_scalar($body['country']) ? trim((string) $body['country']) : '';
        $type = isset($body['type']) && is_scalar($body['type']) ? trim((string) $body['type']) : '';
        $postcode = isset($body['postcode']) && is_scalar($body['postcode']) ? trim((string) $body['postcode']) : '';

        try {
            $location = $this->provider->createPickupLocation([
                'code' => $clean['code'],
                'name' => $clean['name'],
                'contact_name' => $clean['contact_name'],
                'contact_email' => $clean['contact_email'],
                'phone' => $clean['phone'],
                'address' => $clean['address'],
                'city' => $clean['city'],
                'country' => $country !== '' ? $country : 'AE',
                'type' => $type,
                'postcode' => $postcode !== '' ? $postcode : null,
            ]);
        } catch (ShippingException $e) {
            throw new HttpException(
                ShippingException::httpStatusFor($e->kind),
                $e->kind,
                $e->getMessage(),
            );
        }

        return $this->ok(['location' => $location]);
    }
}
