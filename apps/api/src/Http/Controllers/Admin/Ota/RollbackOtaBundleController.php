<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaRollbackException;
use Bayti\Api\Domain\Ota\OtaRollbackService;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OtaBundleSerializer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/ota/bundles/{id}/rollback, one-click rollback to a bundle.
 *
 * Re-publishes the bundle's existing file under the next unused version and
 * makes it the only active bundle for its platform/channel (see
 * {@see OtaRollbackService}). Returns 201 with the new row and the ids that
 * were deactivated.
 */
final class RollbackOtaBundleController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtaRollbackService $rollback,
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
        $source = $this->em->getRepository(OtaBundle::class)->find($id);
        if (!$source instanceof OtaBundle) {
            throw HttpException::notFound('OTA bundle not found.');
        }

        try {
            $result = $this->rollback->rollbackTo($source);
        } catch (OtaRollbackException $e) {
            throw HttpException::badRequest($e->getMessage());
        } catch (UniqueConstraintViolationException) {
            // Two rollbacks raced for the same next version; the other won.
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_DUPLICATE,
                'Another OTA change was published at the same moment. Refresh and try again.',
            );
        }

        return $this->created([
            'bundle' => $this->serializer->shape($result['bundle']),
            'deactivated_ids' => array_map(
                static fn (OtaBundle $b): ?int => $b->getId(),
                $result['deactivated'],
            ),
        ]);
    }
}
