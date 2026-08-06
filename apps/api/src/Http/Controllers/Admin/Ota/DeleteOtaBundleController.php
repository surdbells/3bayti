<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/admin/ota/bundles/{id} — remove a bundle row and its stored .zip.
 *
 * @param array<string, string> $args
 */
final class DeleteOtaBundleController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtaBundleStorageService $storage,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $bundle = $this->em->getRepository(OtaBundle::class)->find($id);
        if (!$bundle instanceof OtaBundle) {
            throw HttpException::notFound('OTA bundle not found.');
        }

        // Best-effort file removal (no-op if the file isn't local / already gone).
        $this->storage->delete($bundle->getPlatform(), $bundle->getVersion());

        $this->em->remove($bundle);
        $this->em->flush();

        return $this->ok(['deleted' => true]);
    }
}
