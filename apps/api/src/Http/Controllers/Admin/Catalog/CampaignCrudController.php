<?php declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Catalog;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignItem;
use Bayti\Api\Domain\Catalog\CampaignRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CampaignSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin campaigns CRUD, routed by method.
 *
 *   GET    /v3/admin/campaigns          list (paginated; ?type, ?active)
 *   POST   /v3/admin/campaigns          create (with items)
 *   GET    /v3/admin/campaigns/{id}     detail (with items)
 *   PUT    /v3/admin/campaigns/{id}     update (replaces items)
 *   DELETE /v3/admin/campaigns/{id}     delete (cascades items)
 *
 * The create/update body carries the full item set; the controller syncs
 * it. On update the old items are deleted in their own flush BEFORE the
 * new ones are inserted, so the (campaign_id, product_id) unique
 * constraint isn't tripped by Doctrine ordering inserts ahead of deletes.
 */
final class CampaignCrudController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $products,
        private readonly CampaignSerializer $campaigns,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $type   = isset($q['type']) && in_array($q['type'], Campaign::TYPES, true) ? (string) $q['type'] : null;
        $active = array_key_exists('active', $q) ? filter_var($q['active'], FILTER_VALIDATE_BOOL) : null;

        /** @var CampaignRepository $repo */
        $repo   = $this->em->getRepository(Campaign::class);
        $result = $repo->findPaginated($limit, $offset, $type, $active);
        $now    = new DateTimeImmutable();

        return $this->ok([
            'data' => array_map(fn (Campaign $c) => $this->summaryShape($c, $now), $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $campaign = $this->findOrFail((int) $request->getAttribute('id'));
        return $this->ok(['data' => $this->campaigns->shape($campaign, $this->products)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body  = (array) ($request->getParsedBody() ?? []);
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw HttpException::badRequest('title is required.');
        }

        $type    = (string) ($body['type'] ?? Campaign::TYPE_ANNIVERSARY);
        $starts  = $this->parseDate($body['starts_at'] ?? null, 'starts_at');
        $ends    = $this->parseDate($body['ends_at'] ?? null, 'ends_at');
        if ($ends <= $starts) {
            throw HttpException::badRequest('ends_at must be after starts_at.');
        }

        $slug = trim((string) ($body['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
            $slug = ($slug !== '' ? $slug : 'campaign') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $campaign = new Campaign($slug, $type, $title, $starts, $ends);
        $this->applyScalars($campaign, $body);

        $this->addItemsFromBody($campaign, $body['items'] ?? []);

        /** @var CampaignRepository $repo */
        $repo = $this->em->getRepository(Campaign::class);
        $repo->save($campaign);

        $full = $repo->findWithItems((int) $campaign->getId()) ?? $campaign;
        return $this->created(['data' => $this->campaigns->shape($full, $this->products)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $campaign = $this->findOrFail((int) $request->getAttribute('id'));
        $body     = (array) ($request->getParsedBody() ?? []);

        if (isset($body['title']) && $body['title'] !== '') {
            $campaign->setTitle((string) $body['title']);
        }
        if (isset($body['type'])) {
            $campaign->setType((string) $body['type']);
        }
        if (array_key_exists('starts_at', $body)) {
            $campaign->setStartsAt($this->parseDate($body['starts_at'], 'starts_at'));
        }
        if (array_key_exists('ends_at', $body)) {
            $campaign->setEndsAt($this->parseDate($body['ends_at'], 'ends_at'));
        }
        if ($campaign->getEndsAt() <= $campaign->getStartsAt()) {
            throw HttpException::badRequest('ends_at must be after starts_at.');
        }
        if (isset($body['slug']) && $body['slug'] !== '') {
            $campaign->setSlug((string) $body['slug']);
        }
        $this->applyScalars($campaign, $body);

        /** @var CampaignRepository $repo */
        $repo = $this->em->getRepository(Campaign::class);

        // Replace items only when the body provides the set. Delete the old
        // ones in their own flush first so inserts of the new set don't hit
        // the (campaign_id, product_id) unique constraint.
        if (array_key_exists('items', $body)) {
            $campaign->clearItems();
            $this->em->flush();
            $this->addItemsFromBody($campaign, $body['items'] ?? []);
        }

        $repo->save($campaign);

        $full = $repo->findWithItems((int) $campaign->getId()) ?? $campaign;
        return $this->ok(['data' => $this->campaigns->shape($full, $this->products)]);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $campaign = $this->findOrFail((int) $request->getAttribute('id'));
        /** @var CampaignRepository $repo */
        $repo = $this->em->getRepository(Campaign::class);
        $repo->delete($campaign);
        return $this->noContent();
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function findOrFail(int $id): Campaign
    {
        /** @var CampaignRepository $repo */
        $repo     = $this->em->getRepository(Campaign::class);
        $campaign = $repo->findWithItems($id);
        if ($campaign === null) {
            throw HttpException::notFound('Campaign not found.');
        }
        return $campaign;
    }

    /** @param array<string,mixed> $body */
    private function applyScalars(Campaign $campaign, array $body): void
    {
        if (array_key_exists('subtitle', $body)) {
            $campaign->setSubtitle($body['subtitle'] !== '' && $body['subtitle'] !== null ? (string) $body['subtitle'] : null);
        }
        if (array_key_exists('discount_percent', $body) && $body['discount_percent'] !== null && $body['discount_percent'] !== '') {
            $campaign->setDiscountPercent((int) $body['discount_percent']);
        }
        if (array_key_exists('is_active', $body)) {
            $campaign->setActive((bool) $body['is_active']);
        }
        if (array_key_exists('priority', $body)) {
            $campaign->setPriority($body['priority'] !== null && $body['priority'] !== '' ? (int) $body['priority'] : null);
        }
    }

    /**
     * Add campaign items from the request body. Dedupes by product_id and
     * skips unknown products. Caller persists via the repository.
     *
     * @param mixed $items
     */
    private function addItemsFromBody(Campaign $campaign, mixed $items): void
    {
        if (!is_array($items)) {
            return;
        }
        $productRepo = $this->em->getRepository(Product::class);
        $seen        = [];
        $order       = 0;

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }
            $product = $productRepo->find($pid);
            if (!$product instanceof Product) {
                continue;
            }
            $seen[$pid] = true;

            $item = new CampaignItem($campaign, $product);
            if (array_key_exists('discount_percent', $row)) {
                $item->setDiscountPercent($this->intOrNull($row['discount_percent']));
            }
            if (array_key_exists('stock_total', $row)) {
                $item->setStockTotal($this->intOrNull($row['stock_total']));
            }
            if (array_key_exists('stock_remaining', $row)) {
                $item->setStockRemaining($this->intOrNull($row['stock_remaining']));
            }
            $item->setSortOrder(isset($row['sort_order']) && $row['sort_order'] !== '' ? (int) $row['sort_order'] : $order);
            $campaign->addItem($item);
            $order++;
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function parseDate(mixed $value, string $field): DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw HttpException::badRequest("$field is required.");
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            throw HttpException::badRequest("$field is not a valid date/time.");
        }
    }

    /** @return array<string,mixed> */
    private function summaryShape(Campaign $c, DateTimeImmutable $now): array
    {
        return [
            'id'               => $c->getId(),
            'slug'             => $c->getSlug(),
            'type'             => $c->getType(),
            'title'            => $c->getTitle(),
            'subtitle'         => $c->getSubtitle(),
            'discount_percent' => $c->getDiscountPercent(),
            'starts_at'        => $c->getStartsAt()->format(DateTimeInterface::ATOM),
            'ends_at'          => $c->getEndsAt()->format(DateTimeInterface::ATOM),
            'is_active'        => $c->isActive(),
            'is_live'          => $c->isLiveAt($now),
            'priority'         => $c->getPriority(),
            'item_count'       => $c->getItems()->count(),
            'created_at'       => $c->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
