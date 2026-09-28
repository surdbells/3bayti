<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OtaBundleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/admin/ota/bundles/{id}, activate/deactivate a bundle.
 *
 * Body: { "is_active": true|false }. Deactivating stops a bundle reaching
 * devices that have not downloaded it yet; devices that already installed it
 * keep it, because the update endpoint only serves strictly newer versions.
 * To move those devices back, use the one-click rollback
 * (POST /v3/admin/ota/bundles/{id}/rollback).
 *
 * @param array<string, string> $args
 */
final class SetOtaBundleActiveController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtaBundleSerializer $serializer,
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

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || !array_key_exists('is_active', $body)) {
            throw HttpException::badRequest('Body must be { "is_active": true|false }.');
        }

        $bundle->setActive((bool) $body['is_active']);
        $this->em->flush();

        return $this->ok(['bundle' => $this->serializer->shape($bundle)]);
    }
}
