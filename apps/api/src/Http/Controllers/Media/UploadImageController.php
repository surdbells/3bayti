<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Media;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/upload
 *
 * Authenticated single-file image upload. Accepts multipart/form-data
 * with the file in a field named "image".
 *
 * Query param `context` controls the storage path:
 *   product          → products/{vendor-slug}/{ulid}.{ext}   (default)
 *   vendor_logo      → vendors/{vendor-slug}/logo.{ext}
 *   vendor_cover     → vendors/{vendor-slug}/cover.{ext}
 *
 * Response (HTTP 201):
 * {
 *   "data": {
 *     "storage_path": "products/my-store/01J....jpg",
 *     "url":          "https://api-v3.3bayti.ae/uploads/products/my-store/01J....jpg",
 *     "mime_type":    "image/jpeg",
 *     "size_bytes":   204800
 *   }
 * }
 *
 * The caller stores the "url" value directly in
 * products.primary_image_url / products.images[] / vendors.logo_url
 * etc. — no further endpoint needed.
 *
 * Cloudflare image transforms are applied by the front-end by
 * wrapping the URL:
 *   /cdn-cgi/image/width=400,quality=80,format=auto/<url>
 * The API always returns canonical origin URLs; transformation is
 * a presentation concern.
 */
final class UploadImageController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ImageStorageService $imageStorage,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Auth — vendor or admin
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        // Uploaded file
        $files  = $request->getUploadedFiles();
        $upload = $files['image'] ?? null;
        if ($upload === null) {
            throw HttpException::badRequest('No file in field "image". Send multipart/form-data with field name "image".');
        }

        // Context determines storage path segment
        $context = trim((string) ($request->getQueryParams()['context'] ?? 'product'));
        if (!in_array($context, ['product', 'vendor_logo', 'vendor_cover'], true)) {
            throw HttpException::badRequest('context must be one of: product, vendor_logo, vendor_cover.');
        }

        // Resolve vendor slug — needed for path namespacing
        $vendorSlug = $this->resolveVendorSlug($user, $context);

        try {
            $stored = match ($context) {
                'vendor_logo'  => $this->imageStorage->storeVendorLogo($upload, $vendorSlug),
                'vendor_cover' => $this->imageStorage->storeVendorCover($upload, $vendorSlug),
                default        => $this->imageStorage->storeProductImage($upload, $vendorSlug),
            };
        } catch (\InvalidArgumentException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        return $this->created(['data' => [
            'storage_path' => $stored->storagePath,
            'url'          => $stored->publicUrl(),
            'mime_type'    => $stored->mimeType,
            'size_bytes'   => $stored->sizeBytes,
        ]]);
    }

    /**
     * Returns a safe slug to use as the storage path vendor segment.
     * Admins uploading on behalf of a vendor (e.g. product context)
     * use their user-id as a fallback slug so files are still isolated.
     */
    private function resolveVendorSlug(User $user, string $context): string
    {
        // If the user is a vendor, use their store slug
        /** @var VendorRepository $repo */
        $repo    = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);

        if (!empty($vendors)) {
            $slug = $vendors[0]->getSlug();
            if ($slug !== '') {
                return $slug;
            }
        }

        // Admins uploading product images: use "admin" + user-id so
        // files are isolated and traceable. Admin product uploads are
        // rare (usually via vendor context) but must not collide.
        return 'admin-' . $user->getId();
    }
}
