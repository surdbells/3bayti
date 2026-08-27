<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
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
 * GET /v3/admin/vendors/{id}/analytics?days=30
 *
 * Admin view of a vendor's analytics dashboard (M3.2.X.13-E).
 * Same 5-section envelope as the vendor self-serve endpoint;
 * admin sees data for ANY vendor regardless of ownership.
 *
 * Q-AdminVisibility = A locked: admins can see vendor dashboards.
 * Audited via AuditEmitter::recordView with the vendor as subject
 * and the window context, matches X.14 admin metrics pattern.
 *
 * Cross-vendor aggregations (e.g. "show me all vendors' analytics
 * in one view") deferred to operator follow-up #34.
 *
 * Authorization: AdminAuthMiddleware → AuthMiddleware stack
 * enforced by route group.
 */
final class GetAdminVendorAnalyticsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorAnalyticsCalculator $calculator,
        private readonly VendorAnalyticsSerializer $serializer,
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

        $analytics = $this->calculator->computeForVendor($vendorId, $windowDays);

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $vendor,
            context: ['context' => 'admin_vendor_analytics', 'window_days' => $windowDays],
        );

        return $this->ok($this->serializer->shape($vendor, $analytics));
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
