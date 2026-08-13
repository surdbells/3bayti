<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\CurrencyConversionService;
use Bayti\Api\Domain\Catalog\ProductReview;
use DateTimeInterface;

/**
 * Serialize Product entities to the contract apps/web (and shortly
 * mobile + portal) expect.
 *
 * Two shapes:
 *   - listShape:   compact card-grid form (what /v3/products returns
 *                  per item).
 *   - detailShape: full product detail (PDP) — superset of listShape.
 *
 * The shapes match apps/web/src/app/features/catalog/product.model.ts:
 *   Product (listShape) + ProductDetail (detailShape).
 *
 * Currency
 * --------
 * Hardcoded AED since this is a UAE marketplace and the legacy schema
 * has no currency column. If we add multi-currency later, this becomes
 * a per-product or per-region field.
 *
 * primary_image fallback
 * ----------------------
 * If `primary_image_url` is set, use it. Otherwise pick the first image
 * from `images` if present. Otherwise null. The frontend Card renders a
 * placeholder when null — we don't need to fabricate one server-side.
 *
 * sizes / colors transformation
 * -----------------------------
 * Legacy stores available sizes as 22 boolean columns; we collapsed to
 * a JSON array of strings on migration. The shape apps/web wants is
 * `[{label, in_stock}, ...]`. Since legacy doesn't track per-size stock,
 * every size is `in_stock: true` if the product has positive stock,
 * false otherwise. This matches what the legacy v2 API returned (we
 * verified by reading the legacy code — it emits a flat "in_stock"
 * boolean per size based on overall product stock).
 *
 * vendor embed
 * ------------
 * We embed `{ slug, name }` rather than the full Vendor object — apps/web
 * needs only those two fields for the card. Saves bytes on long lists.
 */
final class ProductSerializer
{
    private const CURRENCY = 'AED';

    /**
     * Optional display currency for prices. NULL or AED → emit
     * canonical AED amount only (unchanged from pre-X.15 shape).
     * Non-AED → emit dual-amount shape with source preserved.
     */
    private ?Currency $displayCurrency = null;

    public function __construct(
        private readonly ?CurrencyConversionService $conversion = null,
    ) {
    }

    /**
     * Configure the display currency for subsequent shape calls.
     * Catalog controllers call this once per request after reading
     * the CurrencyContextMiddleware attribute. Returns $this for
     * fluent chaining if desired.
     */
    public function withDisplayCurrency(Currency $currency): self
    {
        $this->displayCurrency = $currency;
        return $this;
    }

    /**
     * Convenience: read the display currency from a ServerRequest
     * attribute and configure it on this serializer. Returns $this
     * for chaining.
     *
     * Catalog controllers do:
     *     $this->serializer->configureFromRequest($request)
     *         ->listShapeMany(...);
     *
     * Missing attribute (e.g. middleware not installed in a test
     * environment) defaults to Currency::AED — backward-compatible.
     */
    public function configureFromRequest(\Psr\Http\Message\ServerRequestInterface $request): self
    {
        $currency = $request->getAttribute(
            \Bayti\Api\Http\Middleware\CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
            Currency::AED,
        );
        if (!$currency instanceof Currency) {
            $currency = Currency::AED;
        }
        return $this->withDisplayCurrency($currency);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Vendor product-management list shape. Unlike listShape (the
     * customer-facing storefront shape), this surfaces the management
     * fields the vendor catalog table needs — status, stock quantity,
     * stock status, category NAME, and flat convenience keys (image,
     * price, price_formatted) the table columns + image cell read
     * directly. Used by GET /v3/vendor/products.
     */
    /**
     * Vendor product DETAIL shape — the management shape plus the rich
     * fields the vendor preview drawer needs (description, gallery, sizes,
     * colors). Works for any status (drafts included). Used by
     * GET /v3/vendor/products/{id}.
     */
    public function vendorDetailShape(Product $p): array
    {
        $inStock = $p->isInStock();

        return array_merge($this->vendorManageShape($p), [
            'description' => $p->getDescription() ?? '',
            'images'      => $this->imagesArray($p),
            'sizes'       => array_map(
                static fn (string $label) => ['label' => $label, 'in_stock' => $inStock],
                $p->getAvailableSizes(),
            ),
            'colors'      => array_map(
                static fn (string $label) => ['label' => $label, 'hex_code' => null, 'in_stock' => $inStock],
                $p->getAvailableColors(),
            ),
            'delivery_info' => $p->getDeliveryInfo(),
            'min_order_quantity' => $p->getMinOrderQty(),
            'max_order_quantity' => $p->getMaxOrderQty(),
            'allow_oversell'     => $p->getAllowOversell(),
        ]);
    }

    public function vendorManageShape(Product $p): array
    {
        $primaryImage = $this->primaryImage($p);
        $price = (float) $p->getPrice();
        $category = $p->getCategory();

        return [
            'id'              => $p->getId(),
            'slug'            => $p->getSlug(),
            'name'            => $p->getName(),
            'sku'             => null,
            // Flat keys the management table + image cell read directly.
            'image'           => $primaryImage['url'] ?? null,
            'category'        => $category?->getName(),
            'category_id'     => $category?->getId(),
            'category_slug'   => $category?->getSlug(),
            'status'          => $p->getStatus(),
            'price'           => $price,
            'price_formatted' => 'AED ' . number_format($price, 2),
            'quantity'        => $p->getStockQuantity(),
            'stock_quantity'  => $p->getStockQuantity(),
            'stock_status'    => $p->getStockStatus(),
            // Nested forms kept for any consumer expecting the storefront shape.
            'primary_image'   => $primaryImage,
            'sale_price'      => $p->getSalePrice() !== null ? $this->money($p->getSalePrice()) : null,
            'in_stock'        => $p->isInStock(),
            'label_id'        => $p->getLabelId(),
            'collection_id'   => $p->getCollectionId(),
            'created_at'      => $p->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<Product> $products
     * @return list<array<string, mixed>>
     */
    public function vendorManageShapeMany(iterable $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $out[] = $this->vendorManageShape($p);
        }
        return $out;
    }

    /**
     * Admin GLOBAL product list row — the vendor-scoped manage shape plus a
     * vendor embed, since the admin catalogue spans every store (Store
     * column + vendor filter + "Manage store" row action).
     *
     * @return array<string, mixed>
     */
    public function adminListShape(Product $p): array
    {
        $vendor = $p->getVendor();

        return array_merge($this->vendorManageShape($p), [
            'vendor' => [
                'id'        => $vendor->getId(),
                'name'      => $vendor->getName(),
                'slug'      => $vendor->getSlug(),
                'legacy_id' => $vendor->getLegacyVendorId(),
            ],
        ]);
    }

    /**
     * @param iterable<Product> $products
     * @return list<array<string, mixed>>
     */
    public function adminListShapeMany(iterable $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $out[] = $this->adminListShape($p);
        }
        return $out;
    }

    public function listShape(Product $p): array
    {
        $primaryImage = $this->primaryImage($p);
        $reviewStats = null; // populated only in detailShape for now

        return [
            'id' => $p->getId(),
            'slug' => $p->getSlug(),
            // Legacy product id — mobile cards navigate to the single-product
            // page via GET /v3/products/by-legacy-id/{id}, so the list must
            // surface the legacy id (null for v3-native products with no
            // legacy row). Without it the card sends the v3 id and the
            // by-legacy-id lookup returns NOT_FOUND.
            'legacy_product_id' => $p->getLegacyProductId(),
            'name' => $p->getName(),
            'sku' => null, // legacy has no SKU column; we may add later
            'price' => $this->money($p->getPrice()),
            'sale_price' => $p->getSalePrice() !== null ? $this->money($p->getSalePrice()) : null,
            'primary_image' => $primaryImage,
            // Full gallery so list-driven carousels (mobile explore) can show
            // multiple images per product. imagesArray reads the already-hydrated
            // in-memory images collection — no extra query / no N+1.
            'images' => $this->imagesArray($p),
            'category_id' => $p->getCategory()?->getId(),
            'category_slug' => $p->getCategory()?->getSlug(),
            // Vendor embed. `legacy_id` is REQUIRED by mobile: product cards
            // navigate to the vendor storefront via
            // GET /v3/vendors/by-legacy-id/{id}, keyed on the legacy store id.
            // Without it the card's `store` was undefined and tapping the
            // vendor badge opened an empty storefront (store 0) — the same bug
            // detailShape already fixed for the PDP size guide. Mirror it here.
            'vendor' => $p->getVendor()->getSlug() !== ''
                ? [
                    'slug' => $p->getVendor()->getSlug(),
                    'name' => $p->getVendor()->getName(),
                    'legacy_id' => $p->getVendor()->getLegacyVendorId(),
                    'id' => $p->getVendor()->getId(),
                ]
                : null,
            'rating' => null, // computed later when we have review aggregation
            'review_count' => 0,
            'in_stock' => $p->isInStock(),
            'is_new' => $p->isNew(),
            'is_bestseller' => false, // computed later via order joins (M3)
            // M3.1.5.5 — surface label_id + collection_id on list shape.
            // Mobile's vendors.page renders products grouped by label
            // (`@if(category.id === product.collection)`); the response
            // transform maps label_id → legacy field name `collection`.
            // collection_id is included for symmetry; it's currently
            // unused by mobile but documented as the canonical field.
            'label_id' => $p->getLabelId(),
            'collection_id' => $p->getCollectionId(),
        ];
    }

    /**
     * @param list<Product> $products
     * @return list<array<string, mixed>>
     */
    public function listShapeMany(array $products): array
    {
        return array_map(fn (Product $p) => $this->listShape($p), $products);
    }

    /**
     * Full ProductDetail shape — superset of listShape.
     *
     * @param list<ProductReview> $reviews
     * @return array<string, mixed>
     */
    public function detailShape(Product $p, array $reviews = []): array
    {
        $base = $this->listShape($p);

        // Sizes: legacy doesn't track per-size stock, so in_stock mirrors
        // the product-level boolean.
        $inStock = $p->isInStock();
        $sizes = array_map(
            static fn (string $label) => ['label' => $label, 'in_stock' => $inStock],
            $p->getAvailableSizes(),
        );
        $colors = array_map(
            static fn (string $label) => [
                'label' => $label,
                'hex_code' => null, // legacy doesn't store hex codes
                'in_stock' => $inStock,
            ],
            $p->getAvailableColors(),
        );

        // Override the list-shape vendor block ({slug, name}) with the
        // detail-shape vendor block that ALSO carries the legacy store id.
        // The mobile PDP resolves the store's published size guide via the
        // legacy-id route (GET /v3/vendors/by-legacy-id/{id}/size-chart), so
        // the detail shape must surface the legacy id — without it the PDP's
        // `this.single.store` was 0 and the size-guide fetch hit store 0
        // (empty). `legacy_id` matches the resolver the featured/directory
        // shapes use ($vendor->getLegacyVendorId()); `id` is the v3 id.
        $vendor = $p->getVendor();
        $vendorBlock = $vendor->getSlug() !== ''
            ? [
                'slug' => $vendor->getSlug(),
                'name' => $vendor->getName(),
                'legacy_id' => $vendor->getLegacyVendorId(),
                'id' => $vendor->getId(),
            ]
            : null;

        $detail = array_merge($base, [
            'vendor' => $vendorBlock,
            'description' => $p->getDescription() ?? '',
            'images' => $this->imagesArray($p),
            'sizes' => $sizes,
            'colors' => $colors,
            // Delivery window ({ time, custom_time, note }) — the PDP renders
            // "Delivery: {time} days" (or the custom value). Without this the
            // mobile transform fell back to '' and showed "Delivery: days".
            'delivery_info' => $p->getDeliveryInfo(),
            // Made-to-measure: when requires_measurement is true the PDP shows
            // measurement_instructions and collects the customer's measurement
            // before add-to-cart (enforced server-side in AddCartItemController
            // and at checkout).
            'requires_measurement' => $p->requiresExtraMsmt(),
            'measurement_instructions' => $p->getExtraMsmt(),
            'fabric' => null,
            'care_instructions' => null,
            'materials' => [],
            'related_products' => [], // populated by controller if needed
            'recent_reviews' => array_map(
                fn (ProductReview $r) => $this->reviewShape($r),
                $reviews,
            ),
        ]);

        // Update rating + review_count if reviews provided
        if (count($reviews) > 0) {
            $sum = 0.0;
            foreach ($reviews as $r) {
                $sum += (float) $r->getStar();
            }
            $detail['rating'] = round($sum / count($reviews), 1);
            $detail['review_count'] = count($reviews);
        }

        return $detail;
    }

    /**
     * Render a price as `{amount, currency}` for canonical AED or
     * as `{amount, currency, source_amount, source_currency}` when
     * a non-AED display currency has been configured via
     * withDisplayCurrency() (M3.2.X.15-E).
     *
     * The dual-amount shape preserves the canonical AED amount
     * alongside the display amount so clients can show
     * 'GBP 78.29 (AED 365)' without a second roundtrip.
     *
     * Defensive: if the conversion service is missing OR the
     * configured display currency is AED OR the service returns
     * a non-converted result (rate missing → fallback), emits the
     * single-amount AED shape. This guarantees backward
     * compatibility for any caller that doesn't opt into the
     * currency feature.
     *
     * @return array{amount: float, currency: string, source_amount?: float, source_currency?: string}
     */
    private function money(string $decimalString): array
    {
        if (
            $this->conversion === null
            || $this->displayCurrency === null
            || $this->displayCurrency === Currency::AED
        ) {
            return [
                'amount' => (float) $decimalString,
                'currency' => self::CURRENCY,
            ];
        }

        $r = $this->conversion->convert($decimalString, $this->displayCurrency);
        if (!$r['converted']) {
            // Missing-rate fallback path — emit the AED-only shape
            // so clients don't see a confusing source_currency=AED
            // and currency=AED pair.
            return [
                'amount' => (float) $r['amount'],
                'currency' => $r['currency'],
            ];
        }

        return [
            'amount' => (float) $r['amount'],
            'currency' => $r['currency'],
            'source_amount' => (float) $r['source_amount'],
            'source_currency' => $r['source_currency'],
        ];
    }

    /**
     * @return array{url: string, alt: string|null, width: int|null, height: int|null}|null
     */
    private function primaryImage(Product $p): ?array
    {
        $url = $p->getPrimaryImageUrl();
        if ($url === null || $url === '') {
            $images = $p->getImages();
            $url = $images[0] ?? null;
        }
        if ($url === null) {
            return null;
        }
        return [
            'url' => $url,
            'alt' => $p->getName(),
            'width' => null,
            'height' => null,
        ];
    }

    /**
     * @return list<array{url: string, alt: string|null, width: int|null, height: int|null}>
     */
    private function imagesArray(Product $p): array
    {
        $name = $p->getName();
        $images = $p->getImages();
        // Ensure primary appears first if it's not already in the list
        $primary = $p->getPrimaryImageUrl();
        if ($primary !== null && !in_array($primary, $images, true)) {
            array_unshift($images, $primary);
        }
        return array_map(
            static fn (string $url) => [
                'url' => $url,
                'alt' => $name,
                'width' => null,
                'height' => null,
            ],
            $images,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewShape(ProductReview $r): array
    {
        return [
            'id' => $r->getId(),
            'rating' => (float) $r->getStar(),
            'title' => $r->getTitle(),
            'body' => $r->getComment() ?? '',
            'author' => $r->getReviewerName() ?? 'Anonymous',
            'created_at' => $r->getCreatedAt()->format(DateTimeInterface::ATOM),
            'verified' => $r->isVerified(),
        ];
    }
}
