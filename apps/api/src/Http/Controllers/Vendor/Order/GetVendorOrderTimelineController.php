<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderTimelineSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/orders/{id}/timeline?vendor_id=...&order=desc&limit=50&offset=0
 *
 * Vendor self-serve view of an order's event history (M3.2.X.17-D).
 * Same shape as the admin endpoint (X.17-C) but with vendor scoping
 * applied via OrderTimelineBuilder's vendorIdFilter:
 *
 *   - dispute.* events: HIDDEN (Q-VendorScope = A locked; admin only)
 *   - audit_log rows on OrderItems: shown ONLY if oi.vendor_id matches
 *   - audit_log rows on Order or OrderReturnRequest: HIDDEN
 *   - notifications: shown ONLY if recipient = vendor.contact_email
 *   - return events: filtered to returns touching this vendor's items
 *   - order.created / order.paid: always shown (order-wide context)
 *
 * Multi-store routing (Q-MultiStore from X.14)
 * =============================================
 * Vendor users who own multiple stores must disambiguate via
 * ?vendor_id=N. Without it, the controller 422s with VENDOR_AMBIGUOUS
 * and the list of owned vendor_ids in details. Single-store users
 * don't need the parameter.
 *
 * Cross-vendor existence-leak prevention
 * =======================================
 * If the supplied vendor_id is NOT in the caller's owned set, return
 * 404 (not 403). Standard opaque-on-not-found posture from X.4 + X.18.
 *
 * Order ownership scoping
 * =======================
 * The caller's owned vendor must have at least one item in the order
 * to see its timeline at all. findForVendorIds returns null if no
 * intersection exists → 404. Prevents vendors from browsing other
 * vendors' orders by trying random ids.
 *
 * No audit emission, vendors viewing their own data is non-auditable
 * (same posture as the X.14-C metrics self-serve endpoint).
 */
final class GetVendorOrderTimelineController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderTimelineBuilder $builder,
        private readonly OrderTimelineSerializer $serializer,
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

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($userVendorIds === []) {
            // Defensive, VendorAuthMiddleware should have rejected
            // upstream when the user has no approved stores.
            throw HttpException::forbidden('No approved vendor account.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $chosenVendorId = $this->resolveVendorId($query, $userVendorIds);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        // Verify the caller has at least one item in the order across
        // any of their owned vendors. We pass the full owned set,
        // not just the chosen vendor, a vendor user must be able to
        // SEE that an order touches their store before drilling
        // into a specific store's timeline view. The vendorIdFilter
        // is what narrows the events; this check just gates access.
        $order = $orders->findForVendorIds($orderId, $userVendorIds);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $orderDir = $this->parseOrder($query['order'] ?? null);
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        $result = $this->builder->build(
            orderId: $orderId,
            vendorIdFilter: $chosenVendorId,
            order: $orderDir,
            limit: $limit,
            offset: $offset,
        );

        return $this->ok($this->serializer->shape(
            $order,
            $result['events'],
            $result['total'],
            $limit,
            $offset,
        ));
    }

    /**
     * Choose which vendor's scope to apply. Same routing as the
     * X.14-C self-metrics endpoint.
     *
     * @param array<string, mixed> $query
     * @param list<int> $userVendorIds
     */
    private function resolveVendorId(array $query, array $userVendorIds): int
    {
        $requested = $query['vendor_id'] ?? null;
        if ($requested !== null && $requested !== '') {
            if (!is_string($requested) && !is_int($requested)) {
                throw HttpException::notFound('Order not found.');
            }
            if (!ctype_digit((string) $requested)) {
                throw HttpException::notFound('Order not found.');
            }
            $id = (int) $requested;
            if (!in_array($id, $userVendorIds, true)) {
                throw HttpException::notFound('Order not found.');
            }
            return $id;
        }

        if (count($userVendorIds) === 1) {
            return $userVendorIds[0];
        }

        throw new HttpException(
            status: 422,
            errorCode: 'VENDOR_AMBIGUOUS',
            publicMessage: 'Multiple vendor accounts available; supply vendor_id to choose.',
            details: ['available_vendor_ids' => $userVendorIds],
        );
    }

    private function parseOrder(mixed $raw): string
    {
        if (!is_string($raw)) {
            return 'desc';
        }
        return $raw === 'asc' ? 'asc' : 'desc';
    }

    private function clampLimit(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return OrderTimelineBuilder::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return OrderTimelineBuilder::DEFAULT_LIMIT;
        }
        return min($n, OrderTimelineBuilder::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return 0;
        }
        return max(0, (int) $raw);
    }
}
