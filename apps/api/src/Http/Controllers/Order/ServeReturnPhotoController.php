<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use Bayti\Api\Domain\Order\OrderReturnRequestPhotoRepository;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Order\ReturnPhotoStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * GET /v3/returns/{id}/photos/{photoId}
 *
 * Auth-gated streaming of return-request photo evidence.
 *
 * Authorization (Q-Photos + Q-VendorRole locked):
 *   - The order's customer can see all photos on their return
 *   - A vendor can see photos when at least one item in the
 *     return belongs to them (one of their products was returned)
 *   - An admin can see all photos
 *   - Anyone else → 404 (matches the existence-leak prevention
 *     pattern used by the other return endpoints)
 *
 * Anti-enumeration
 * ================
 * The {photoId} segment must belong to the {id} segment's return
 * request. The repo method findByIdAndRequest enforces this, an
 * attacker who guesses {id} from their own return + a photoId
 * from somewhere else gets 404, not the wrong photo.
 *
 * Response
 * ========
 * 200 with Content-Type matching the photo's mime_type +
 * Content-Length set + body streamed via Flysystem readStream.
 * No buffer-the-whole-blob-in-PHP path, large photos pump byte
 * by byte through the stream.
 */
final class ServeReturnPhotoController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnPhotoStorageService $photoStorage,
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
        $photoId = (int) ($args['photoId'] ?? 0);
        if ($returnId <= 0 || $photoId <= 0) {
            throw HttpException::notFound('Photo not found.');
        }

        /** @var OrderReturnRequestRepository $returnRepo */
        $returnRepo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $returnRepo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Photo not found.');
        }

        if (!$this->canAccessPhotos($user, $returnRequest)) {
            // Use 404 (not 403) per the existence-leak prevention
            // pattern. Same response shape an attacker would get for
            // a non-existent photoId.
            throw HttpException::notFound('Photo not found.');
        }

        /** @var OrderReturnRequestPhotoRepository $photoRepo */
        $photoRepo = $this->em->getRepository(OrderReturnRequestPhoto::class);
        $photo = $photoRepo->findByIdAndRequest($photoId, $returnId);
        if ($photo === null) {
            throw HttpException::notFound('Photo not found.');
        }

        try {
            $stream = $this->photoStorage->readStream($photo->getStoragePath());
        } catch (FilesystemException) {
            // The DB row exists but the blob is missing. Treat as 404
            // for the client; operator cron will notice the orphan.
            throw HttpException::notFound('Photo not found.');
        }

        $response = $this->responseFactory->createResponse(200);
        $response = $response
            ->withHeader('Content-Type', $photo->getMimeType())
            ->withHeader('Content-Length', (string) $photo->getSizeBytes())
            // Photos are personal data; never let intermediaries cache.
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withBody(new Stream($stream));

        return $response;
    }

    /**
     * 3-branch authorization:
     *   1. Admin → yes
     *   2. Customer who owns the order → yes
     *   3. Vendor who has at least one item in the return → yes
     *   4. Otherwise → no
     */
    private function canAccessPhotos(User $user, OrderReturnRequest $returnRequest): bool
    {
        // Branch 1: admin.
        if ($user->isAdmin()) {
            return true;
        }

        // Branch 2: order's customer.
        if ($returnRequest->getCustomer()->getId() === $user->getId()) {
            return true;
        }

        // Branch 3: vendor with items in this return. We resolve
        // ALL vendor ids the user owns, then check intersection
        // with the return's vendor ids.
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($userVendorIds === []) {
            return false;
        }
        $returnVendorIds = $returnRequest->getVendorIds();
        foreach ($returnVendorIds as $vid) {
            if (in_array($vid, $userVendorIds, true)) {
                return true;
            }
        }
        return false;
    }
}
