<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
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
 * GET /v3/admin/vendors/{id}/metrics?days=30
 *
 * Returns 4 performance rates for a single vendor over the supplied
 * rolling window (M3.2.X.14-B):
 *
 *   - fulfillment_rate
 *   - cancellation_rate
 *   - return_rate
 *   - dispute_rate
 *
 * Each rate carries the numerator + denominator so the UI can show
 * "23 of 245 items" rather than only "9.4%".
 *
 * Window:
 *   - days defaults to 30 (DEFAULT_WINDOW_DAYS)
 *   - clamped to MIN_WINDOW_DAYS (7) — MAX_WINDOW_DAYS (365) range
 *   - non-numeric / blank values fall back to default
 *
 * Authorization: admin-only (group middleware in routes.php enforces
 * AdminAuthMiddleware → AuthMiddleware stack). Audit ACTION_VIEWED
 * emitted on every successful call with the vendor as subject.
 */
final class GetAdminVendorMetricsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorMetricsCalculator $calculator,
        private readonly VendorMetricsSerializer $serializer,
        private readonly AuditEmitter $audit,
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

        $vendorId = (int) ($args['id'] ?? 0);
        if ($vendorId <= 0) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $windowDays = $this->parseWindowDays($query['days'] ?? null);

        $metrics = $this->calculator->computeForVendor($vendorId, $windowDays);

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $vendor,
            context: ['context' => 'admin_vendor_metrics', 'window_days' => $windowDays],
        );

        return $this->ok($this->serializer->singleShape($vendor, $metrics));
    }

    /**
     * Clamp + default the window param. Non-numeric or out-of-range
     * inputs fall back to DEFAULT_WINDOW_DAYS rather than 400 — the
     * metrics endpoint is read-only and degrades cleanly.
     */
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
