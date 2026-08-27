<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/gift-cards/photo
 *
 * Authenticated single-image upload for the LUXURY gift-card theme's
 * recipient photo. Accepts multipart/form-data with the file in a field
 * named "image".
 *
 * This is deliberately separate from the vendor/admin POST /v3/upload:
 *   - the caller is a regular *customer* buying a gift card, not a vendor;
 *   - the file is namespaced under gift-cards/{user-id}/, not products/;
 *   - no vendor-slug resolution is involved.
 *
 * Response (HTTP 201):
 * {
 *   "data": {
 *     "storage_path": "gift-cards/42/01J....jpg",
 *     "url":          "https://api-v3.3bayti.ae/uploads/gift-cards/42/01J....jpg",
 *     "mime_type":    "image/jpeg",
 *     "size_bytes":   204800
 *   }
 * }
 *
 * The web client takes the returned "url" and passes it as
 * `recipient_photo_url` to POST /v3/gift-cards/purchase (luxury theme
 * only, the entity + purchase controller reject a photo on any other
 * theme). The stored image is public-by-URL so the recipient can render
 * it on the card; the ULID filename keeps the URL unguessable.
 */
final class UploadGiftCardPhotoController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ImageStorageService $imageStorage,
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

        $files  = $request->getUploadedFiles();
        $upload = $files['image'] ?? null;
        if ($upload === null) {
            throw HttpException::badRequest(
                'No file in field "image". Send multipart/form-data with field name "image".'
            );
        }

        try {
            $stored = $this->imageStorage->storeGiftCardPhoto($upload, $user->getId() ?? 0);
        } catch (\InvalidArgumentException $e) {
            // Mime / size / upload-error problems are client errors, surface
            // the storage service's message as a clean 400 rather than a 500.
            throw HttpException::badRequest($e->getMessage());
        }

        return $this->created(['data' => [
            'storage_path' => $stored->storagePath,
            'url'          => $stored->publicUrl(),
            'mime_type'    => $stored->mimeType,
            'size_bytes'   => $stored->sizeBytes,
        ]]);
    }
}
