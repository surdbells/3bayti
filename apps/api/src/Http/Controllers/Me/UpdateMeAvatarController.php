<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

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
 * POST /v3/me/avatar
 *
 * Upload (or replace) the authenticated user's profile picture. Accepts a
 * base64 data URL in { "image": "data:image/png;base64,..." }, stores it to
 * the PUBLIC uploads store under avatars/user-{id}/..., persists the resulting
 * public URL on the user, and returns it. Used by both the mobile customer
 * profile and the portal user profile.
 */
final class UpdateMeAvatarController
{
    use Responder;

    /** Accepted avatar mime types → file extension. */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Cap on the decoded image size (~5 MB). */
    private const MAX_BYTES = 5_000_000;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ImageStorageService $storage,
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
        $dataUrl = trim((string) ($body['image'] ?? ''));
        if ($dataUrl === '') {
            throw HttpException::badRequest('An image is required.');
        }
        if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $dataUrl, $m)) {
            throw HttpException::badRequest('Image must be a base64 data URL.');
        }
        $mime = strtolower($m[1]);
        if (!isset(self::MIME_EXT[$mime])) {
            throw HttpException::badRequest('Unsupported image type. Use JPEG, PNG, or WebP.');
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            throw HttpException::badRequest('Image payload is invalid.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw HttpException::badRequest('Image is too large (max 5 MB).');
        }

        $path = sprintf(
            'avatars/user-%d/%s.%s',
            (int) $user->getId(),
            bin2hex(random_bytes(8)),
            self::MIME_EXT[$mime],
        );
        $stored = $this->storage->storeRaw($bytes, $path);
        $url = $stored->publicUrl();

        $user->setAvatarUrl($url);
        $this->em->flush();

        return $this->ok(['data' => ['avatar_url' => $url]]);
    }
}
