<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Catalog;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/recommendations/{product_id}/explain
 *
 * Admin debug endpoint (M3.2.X.12-G). Returns the full
 * recommendation breakdown for a product, grouped by source
 * (copurchase / category / fallback_popular) so an admin can
 * answer "why is competitor X recommended on my product page".
 *
 * Q-AdminVisibility = A locked. Audited via AuditEmitter::recordView
 * with context 'admin_recommendations_explain'.
 *
 * Returns ALL rows (not capped to limit) since the operational
 * use case is debugging, not display. Typical row count is the
 * cron's TOP_N_TARGET = 20 per source product.
 */
final class GetAdminRecommendationsExplainController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly RecommendationsService $recommendations,
        private readonly RecommendationsSerializer $serializer,
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

        $productId = (int) ($args['product_id'] ?? 0);
        if ($productId <= 0) {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->find($productId);
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }

        $recs = $this->recommendations->getExplainForProduct($productId);

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $product,
            context: [
                'context' => 'admin_recommendations_explain',
                'recommendation_count' => count($recs),
            ],
        );

        return $this->ok($this->serializer->explainShape($productId, $recs));
    }
}
