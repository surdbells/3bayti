<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/admin/ota/bundles/{id}, remove a bundle row and its stored .zip
 * (the file is kept while another row, e.g. a rollback, still serves it).
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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        $bundle = $this->em->getRepository(OtaBundle::class)->find($id);
        if (!$bundle instanceof OtaBundle) {
            throw HttpException::notFound('OTA bundle not found.');
        }

        // Best-effort file removal (no-op if the file isn't local / already
        // gone), but only when no other row still points at the same file: a
        // rollback re-publishes its source bundle's file, so deleting either
        // row must not break the other's download.
        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        if ($repo->countByUrl($bundle->getUrl()) <= 1) {
            $this->storage->deleteUrl($bundle->getUrl());
        }

        $this->em->remove($bundle);
        $this->em->flush();

        return $this->ok(['deleted' => true]);
    }
}
