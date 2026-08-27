<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorAnalyticsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/analytics?days=30&vendor_id=...
 *
 * Vendor self-serve dashboard view (M3.2.X.13-E). Returns the
 * 5-section analytics envelope from VendorAnalyticsCalculator.
 *
 * Multi-store routing mirrors GetVendorSelfMetricsController (X.14):
 *   - Single-store user: no ?vendor_id needed
 *   - Multi-store user without ?vendor_id: 422 VENDOR_AMBIGUOUS
 *     with the list so the client can prompt
 *   - ?vendor_id pointing to a store the user doesn't own: 404
 *     (opaque cross-tenant pattern)
 *
 * Authorization: VendorAuthMiddleware enforces 'approved vendor'
 * on the route group. No further auth check needed here.
 *
 * No audit emission, vendors viewing their own data is non-
 * auditable (same as X.14 self-metrics).
 */
final class GetVendorSelfAnalyticsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorAnalyticsCalculator $calculator,
        private readonly VendorAnalyticsSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);

        if ($userVendorIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $vendorId = $this->resolveVendorId($query, $userVendorIds);

        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $windowDays = $this->parseWindowDays($query['days'] ?? null);
        $analytics = $this->calculator->computeForVendor($vendorId, $windowDays);

        return $this->ok($this->serializer->shape($vendor, $analytics));
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int> $userVendorIds
     */
    private function resolveVendorId(array $query, array $userVendorIds): int
    {
        $requested = $query['vendor_id'] ?? null;
        if ($requested !== null && $requested !== '') {
            if (!is_string($requested) && !is_int($requested)) {
                throw HttpException::notFound('Vendor not found.');
            }
            if (!ctype_digit((string) $requested)) {
                throw HttpException::notFound('Vendor not found.');
            }
            $id = (int) $requested;
            if (!in_array($id, $userVendorIds, true)) {
                throw HttpException::notFound('Vendor not found.');
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

    private function parseWindowDays(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return VendorAnalyticsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return VendorAnalyticsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $days = (int) $rawStr;
        if ($days < VendorAnalyticsCalculator::MIN_WINDOW_DAYS) {
            return VendorAnalyticsCalculator::MIN_WINDOW_DAYS;
        }
        if ($days > VendorAnalyticsCalculator::MAX_WINDOW_DAYS) {
            return VendorAnalyticsCalculator::MAX_WINDOW_DAYS;
        }
        return $days;
    }
}
