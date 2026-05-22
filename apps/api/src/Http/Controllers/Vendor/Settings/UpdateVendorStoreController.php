<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Settings;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/vendor/store
 *
 * Update the authenticated vendor's own store basic profile.
 * All fields are optional (true PATCH semantics).
 */
final class UpdateVendorStoreController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
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

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if (empty($vendors)) {
            throw HttpException::notFound('No vendor account found.');
        }

        $vendor = $vendors[0];
        $body   = (array) ($request->getParsedBody() ?? []);

        if (isset($body['name']) && is_string($body['name']) && $body['name'] !== '') {
            $vendor->setName($body['name']);
        }
        if (isset($body['description'])) {
            $vendor->setDescription($body['description'] !== '' ? (string) $body['description'] : null);
        }
        if (isset($body['store_name']) && is_string($body['store_name']) && $body['store_name'] !== '') {
            $vendor->setName($body['store_name']);
        }
        if (isset($body['store_description'])) {
            $vendor->setDescription($body['store_description'] !== '' ? (string) $body['store_description'] : null);
        }
        if (isset($body['contact_email']) && is_string($body['contact_email'])) {
            $vendor->setContactEmail($body['contact_email']);
        }
        if (isset($body['store_email']) && is_string($body['store_email'])) {
            $vendor->setContactEmail($body['store_email']);
        }
        if (isset($body['contact_phone'])) {
            $vendor->setContactPhone($body['contact_phone'] !== '' ? (string) $body['contact_phone'] : null);
        }
        if (isset($body['store_phone'])) {
            $vendor->setContactPhone($body['store_phone'] !== '' ? (string) $body['store_phone'] : null);
        }
        if (isset($body['logo_url'])) {
            $vendor->setLogoUrl($body['logo_url'] !== '' ? (string) $body['logo_url'] : null);
        }
        if (isset($body['store_logo'])) {
            $vendor->setLogoUrl($body['store_logo'] !== '' ? (string) $body['store_logo'] : null);
        }
        if (isset($body['cover_image_url'])) {
            $vendor->setCoverImageUrl($body['cover_image_url'] !== '' ? (string) $body['cover_image_url'] : null);
        }
        if (isset($body['store_cover'])) {
            $vendor->setCoverImageUrl($body['store_cover'] !== '' ? (string) $body['store_cover'] : null);
        }

        $repo->save($vendor);

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->adminShape($vendor),
        ));
    }
}
