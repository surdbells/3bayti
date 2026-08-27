<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorDashboardCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * GET /v3/vendor/dashboard?days=30
 *
 * A single insightful, balanced dashboard payload across ALL of the
 * authenticated user's owned stores: catalog health, sales (period +
 * all-time), a daily revenue trend, top products, an operations queue
 * (items awaiting action, low stock), and recent orders.
 *
 * Replaces the old dashboard's reliance on GET /vendor/metrics, which only
 * returned order-fulfilment rates (legitimately zero with no orders) and no
 * product count or sales, leaving the whole dashboard reading zero.
 */
final class GetVendorDashboardController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorDashboardCalculator $calculator,
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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ownedIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($ownedIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        $query = $request->getQueryParams();

        // Optional: scope to a single owned store; default = all stores.
        $vendorIds = array_values(array_map('intval', $ownedIds));
        if (!empty($query['vendor_id'])) {
            $requested = (int) $query['vendor_id'];
            if (!in_array($requested, $vendorIds, true)) {
                throw HttpException::notFound('Vendor not found.');
            }
            $vendorIds = [$requested];
        }

        $days = $this->parseDays($query['days'] ?? null);

        return $this->ok(['data' => $this->calculator->compute($vendorIds, $days)]);
    }

    private function parseDays(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return VendorDashboardCalculator::DEFAULT_WINDOW_DAYS;
        }
        return (int) $raw; // clamped inside the calculator
    }
}
