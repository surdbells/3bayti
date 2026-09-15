<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\CompleteLook\CompleteTheLookService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/{id}/complete-the-look — complementary products for a PDP.
 *
 * Public. {id} is the v3 product id (never a legacy id). Returns real, in-stock
 * complements that pair with the seed, each with a short reason and its category
 * slug; the client renders a "Complete the look" strip and can loop the existing
 * POST /v3/cart/items to "add the look". Records a complete_look_viewed event.
 */
final class GetCompleteTheLookController
{
    use Responder;

    private const MIN_LIMIT = 4;
    private const MAX_LIMIT = 8;
    private const DEFAULT_LIMIT = 6;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CompleteTheLookService $service,
        private readonly ProductSerializer $productSerializer,
        private readonly AiEventLogger $events,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $seed = $productRepo->find((int) $rawId);
        if ($seed === null || !$seed->isOrderable()) {
            throw HttpException::notFound('Product not found.');
        }

        $params = $request->getQueryParams();
        $locale = $this->resolveLocale($params['locale'] ?? null);
        $limit = $this->resolveLimit($params['limit'] ?? null);
        $sessionId = isset($params['session_id']) && is_scalar($params['session_id'])
            ? mb_substr((string) $params['session_id'], 0, 64) : null;

        $items = $this->service->forProduct($seed, $locale, $limit);
        $productIds = [];
        foreach ($items as $item) {
            $id = $item->product->getId();
            if ($id !== null) {
                $productIds[] = $id;
            }
        }

        $seedId = (int) $rawId;
        $interactionId = $this->events->recordInteraction(
            'complete_the_look',
            'seed:' . $seedId,
            null,
            $productIds,
            null,
            $sessionId,
            null,
            $locale,
        );
        $this->events->recordEvent(
            'complete_look_viewed',
            $interactionId,
            null,
            $sessionId,
            $seedId,
            null,
            ['count' => count($items)],
        );

        $serializer = $this->productSerializer->configureFromRequest($request);
        $cards = [];
        foreach ($items as $item) {
            $card = $serializer->listShape($item->product);
            $card['reason'] = $item->reason;
            $card['complement_category'] = $item->complementCategorySlug;
            $cards[] = $card;
        }

        return $this->ok([
            'interaction_id' => $interactionId,
            'seed_product_id' => $seedId,
            'items' => $cards,
        ]);
    }

    private function resolveLocale(mixed $locale): string
    {
        $candidate = is_string($locale) ? strtolower(substr(trim($locale), 0, 2)) : '';
        return $candidate === 'ar' ? 'ar' : 'en';
    }

    private function resolveLimit(mixed $limit): int
    {
        $n = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
        return max(self::MIN_LIMIT, min(self::MAX_LIMIT, $n));
    }
}
