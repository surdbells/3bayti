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
 * Only is_active changes: a re-activated row keeps its native range
 * (min_native_version .. effective max), so it is again served only to the
 * native app versions it was published for. Activating a row whose range
 * matches no installed app (it ends at 0, e.g. a legacy row from the old
 * "0.0.0" min default; {@see OtaBundle::reachesNoShell()}) is refused with a
 * 400 instead of silently serving nobody. Deactivating is always allowed.
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

        $active = (bool) $body['is_active'];
        if ($active && $bundle->reachesNoShell()) {
            throw HttpException::badRequest(sprintf(
                '%s %s is published for native app version %s, which no installed app reports, so activating it would '
                . 'serve no device. Widen its native range first (set its max_native_version to the app version it was '
                . 'built for, e.g. 1.8: see OTA-DEPLOY.md), or upload its build again as a new version with Min native '
                . 'set to that app version.',
                $bundle->getPlatform(),
                $bundle->getVersion(),
                $bundle->nativeRangeLabel(),
            ));
        }

        $bundle->setActive($active);
        $this->em->flush();

        return $this->ok(['bundle' => $this->serializer->shape($bundle)]);
    }
}
