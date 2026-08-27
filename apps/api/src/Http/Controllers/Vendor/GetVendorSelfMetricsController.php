<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMetricsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorMetricsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/metrics?days=30&vendor_id=...
 *
 * Vendor self-serve view of own performance metrics (M3.2.X.14-C).
 * Returns the same 4 rates as the admin endpoint, scoped to a vendor
 * the calling user owns.
 *
 * Multi-store users:
 *   Users with multiple approved stores must supply ?vendor_id=N
 *   to disambiguate. Without it, when the user owns multiple stores,
 *   we 422 with VENDOR_AMBIGUOUS so the client can prompt them to
 *   pick a store. Single-store users don't need the parameter.
 *
 * Authorization: VendorAuthMiddleware enforces 'approved vendor' on
 * the route group. No further auth check needed in the controller.
 *
 * No audit emission, vendors viewing their own data is non-auditable
 * (it's their data; emitting an audit trail for self-views would be
 * noise without forensic value).
 */
final class GetVendorSelfMetricsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorMetricsCalculator $calculator,
        private readonly VendorMetricsSerializer $serializer,
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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);

        // VendorAuthMiddleware already guaranteed at least one
        // approved vendor exists; defensive recheck.
        if ($userVendorIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $vendorId = $this->resolveVendorId($query, $userVendorIds);

        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            // Defensive, would only happen if a vendor row was
            // deleted between the findIdsByOwnerUser query and the
            // find call.
            throw HttpException::notFound('Vendor not found.');
        }

        $windowDays = $this->parseWindowDays($query['days'] ?? null);
        $metrics = $this->calculator->computeForVendor($vendorId, $windowDays);

        return $this->ok($this->serializer->singleShape($vendor, $metrics));
    }

    /**
     * Choose which vendor's metrics to return:
     *   - Caller supplied ?vendor_id=N → that ID, IF in their owned set
     *     (otherwise 404, opaque "not found", standard cross-tenant
     *     pattern)
     *   - Caller owns exactly one store → that one
     *   - Caller owns multiple stores AND no vendor_id supplied → 422
     *     VENDOR_AMBIGUOUS with the list so the client can prompt
     *
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

        // Ambiguous: multi-store user must pick one
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
            return VendorMetricsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return VendorMetricsCalculator::DEFAULT_WINDOW_DAYS;
        }
        $days = (int) $rawStr;
        if ($days < VendorMetricsCalculator::MIN_WINDOW_DAYS) {
            return VendorMetricsCalculator::MIN_WINDOW_DAYS;
        }
        if ($days > VendorMetricsCalculator::MAX_WINDOW_DAYS) {
            return VendorMetricsCalculator::MAX_WINDOW_DAYS;
        }
        return $days;
    }
}
