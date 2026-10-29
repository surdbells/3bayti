<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\TryOn;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/try-on/status
 *
 * Deployment readiness for virtual try-on, for the admin enablement screen:
 *
 *   { data: { available, tryon_flag, ai_enabled, enabled_products, eligible_formats } }
 *
 *   - available         the live provider is enabled (TRYON_ENABLED AND
 *                       AI_ENABLED AND OPENAI_API_KEY set). Customers only see
 *                       "Try it on" when this is true.
 *   - tryon_flag        raw TRYON_ENABLED env value
 *   - ai_enabled        raw AI_ENABLED env value
 *   - enabled_products  products flagged try_on_enabled (soft-deleted hidden)
 *   - eligible_formats  garment image formats the worker accepts
 *
 * The provider comes from the fault-tolerant 'tryon.provider.safe' DI entry, so
 * a misconfigured provider (e.g. TRYON_ENABLED without a key) reports
 * available=false here instead of a 500.
 *
 * Authorization: admin-only (group middleware). products.view.
 */
final class GetAdminTryOnStatusController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly VirtualTryOnProviderInterface $provider,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);

        return $this->ok(['data' => [
            'available' => $this->provider->isEnabled(),
            'tryon_flag' => self::envFlag('TRYON_ENABLED'),
            'ai_enabled' => self::envFlag('AI_ENABLED'),
            'enabled_products' => $repo->countTryOnEnabled(),
            'eligible_formats' => TryOnGarmentEligibility::ELIGIBLE_FORMATS,
        ]]);
    }

    /** Same parsing as the provider factory in config/di.php. */
    private static function envFlag(string $name): bool
    {
        return filter_var($_ENV[$name] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
