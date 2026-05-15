<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\Order\OrderDisputeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\DisputeSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /v3/admin/disputes?limit=10&offset=0&status=open
 *
 * Paginated list of all order disputes. Newest first.
 *
 * Status filter accepts any of OrderDispute::ALL_STATUSES. Most
 * common admin query: ?status=open (untriaged disputes).
 *
 * Pagination: limit 1-100 (default 10), offset 0+.
 *
 * Q5=A: list reads aren't audited individually (would flood the
 * audit_log table with one row per page load). Instead, we log
 * structured to operational logs for usage analytics. Detail views
 * (GetDisputeController) DO emit ACTION_VIEWED per Q5=A.
 */
final class ListDisputesController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly DisputeSerializer $serializer,
        private readonly LoggerInterface $logger,
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $status = $this->parseStatus($query['status'] ?? null);

        /** @var OrderDisputeRepository $repo */
        $repo = $this->em->getRepository(OrderDispute::class);
        [$list, $total] = $repo->paginated($limit, $offset, $status);

        $items = array_map(
            fn (OrderDispute $d): array => $this->serializer->shape($d),
            $list,
        );

        $this->logger->info('admin.disputes.listed', [
            'actor_user_id' => $user->getId(),
            'status_filter' => $status,
            'returned' => count($items),
        ]);

        return $this->ok([
            'disputes' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
            ],
        ]);
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

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        $n = (int) $raw;
        return max(0, $n);
    }

    private function parseStatus(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || !is_string($raw)) {
            return null;
        }
        return in_array($raw, OrderDispute::ALL_STATUSES, true) ? $raw : null;
    }
}
