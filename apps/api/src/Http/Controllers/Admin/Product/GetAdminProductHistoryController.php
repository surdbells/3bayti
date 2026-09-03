<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Audit\AuditLogRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AuditLogSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/products/{id}/history
 *
 * The change history (timeline) for a single product: who did what, when.
 * Reads the append-only audit_log for subject_type='Product' (the type
 * EntityAuditListener records product create/update/delete under), newest
 * first, and denormalises the actor name/email per page like the main
 * audit surface so there is no N+1.
 *
 * Deliberately does NOT record a 'viewed' event for the product: doing so
 * would insert a row into the very timeline it's returning, so opening the
 * history would pollute the history. Reading the trail leaves no trace.
 *
 * Authorization: admin-only (group middleware). products.view — the same
 * gate as GetAdminProductController, so anyone who can see the product can
 * see how it changed.
 */
final class GetAdminProductHistoryController
{
    use Responder;

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->find($id);
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }

        $limit = $this->clampLimit($request->getQueryParams()['limit'] ?? null);

        /** @var AuditLogRepository $repo */
        $repo = $this->em->getRepository(AuditLog::class);
        $rows = $repo->findForSubject('Product', $id, $limit);

        $actors = $this->loadActors($rows);
        $items = array_map(
            fn (AuditLog $log): array => $this->serializer->shape($log, $actors),
            $rows,
        );

        return $this->ok([
            'product' => [
                'id' => (int) $product->getId(),
                'name' => $product->getName(),
            ],
            'logs' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * Batch-load the distinct actor users for the timeline so the actor
     * name/email is one query for all rows, not one per row.
     *
     * @param AuditLog[] $rows
     * @return array<int, array{name: string|null, email: string|null}>
     */
    private function loadActors(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $uid = $row->getUserId();
            if ($uid !== null) {
                $ids[$uid] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $users = $this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]);
        $actors = [];
        foreach ($users as $u) {
            /** @var User $u */
            $name = trim(($u->getFirstName() ?? '') . ' ' . ($u->getLastName() ?? ''));
            $actors[(int) $u->getId()] = [
                'name' => $name !== '' ? $name : null,
                'email' => $u->getEmail(),
            ];
        }
        return $actors;
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($n, self::MAX_LIMIT);
    }
}
