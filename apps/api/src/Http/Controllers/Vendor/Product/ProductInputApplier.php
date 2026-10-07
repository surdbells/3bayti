<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Http\Controllers\Vendor\Product\Dto\VendorProductInput;

/**
 * Applies a VendorProductInput to a Product, the ONE field-application path
 * shared by every product write endpoint:
 *
 *   - POST /v3/vendor/products       (CreateVendorProductController)
 *   - PUT  /v3/vendor/products/{id}  (UpdateVendorProductController)
 *   - POST /v3/admin/products        (CreateAdminProductController)
 *   - PUT  /v3/admin/products/{id}   (UpdateAdminProductController)
 *
 * The admin endpoints used to carry their own hand-picked subset of fields,
 * so admin edits to stock, order limits, cost, merchandising flags and
 * made-to-measure were silently discarded. Routing all four through here
 * means a DTO field can never again be honoured by one surface and dropped
 * by another.
 *
 * Semantics (partial update, despite PUT):
 *   - every field is applied only when non-null; absent/null keeps the stored value.
 *   - sale_price is the exception: it is applied (incl. an explicit null, which
 *     clears the discount) only when the request body carried the key, see
 *     $salePricePresent. The create endpoints always pass true, so a new
 *     product takes the sent value or none.
 *   - label_id is NOT read from the DTO: callers validate it through
 *     ProductLabelValidator BEFORE any mutation and pass the resolved id here;
 *     null leaves the label untouched.
 *   - the category is likewise resolved by the caller; null leaves it untouched.
 *   - collection_id is applied only when $applyCollection is true. The admin
 *     endpoints pass false: admin storefront collections are curated separately.
 *
 * The application ORDER is significant and must stay as is: setStockQuantity()
 * auto-derives stock_status (from the pre-update allow_oversell), so an
 * explicit stock_status sent alongside it is applied AFTER it and wins.
 */
final class ProductInputApplier
{
    public function apply(
        Product $product,
        VendorProductInput $input,
        ?Category $category,
        ?int $labelId,
        bool $salePricePresent,
        bool $applyCollection = true,
    ): void {
        if ($input->name !== null) {
            $product->setName($input->name);
        }
        if ($input->description !== null) {
            $product->setDescription($input->description);
        }
        if ($input->price !== null) {
            $product->setPrice(self::decimal($input->price));
        }
        // Apply sale_price ONLY when the request includes the key, so an
        // explicit value (or null) sets/clears the discount while a partial
        // update that omits it preserves the stored value. The portal form
        // always sends the key (null = not on sale).
        if ($salePricePresent) {
            $product->setSalePrice($input->sale_price !== null ? self::decimal($input->sale_price) : null);
        }
        if ($input->cost_per_item !== null) {
            $product->setCostPerItem(self::decimal($input->cost_per_item));
        }
        if ($input->stock_quantity !== null) {
            $product->setStockQuantity($input->stock_quantity);
        }
        if ($input->stock_status !== null) {
            $product->setStockStatus($input->stock_status);
        }
        if ($input->allow_oversell !== null) {
            $product->setAllowOversell($input->allow_oversell);
        }
        if ($input->min_order_qty !== null) {
            $product->setMinOrderQty($input->min_order_qty);
        }
        if ($input->max_order_qty !== null) {
            $product->setMaxOrderQty($input->max_order_qty);
        }
        if ($input->primary_image_url !== null) {
            $product->setPrimaryImageUrl($input->primary_image_url);
        }
        if ($input->image_urls !== null) {
            $product->setImages($input->image_urls);
        }
        if ($input->sizes !== null) {
            $product->setAvailableSizes($input->sizes);
        }
        if ($input->colors !== null) {
            $product->setAvailableColors($input->colors);
        }
        if ($input->is_featured !== null) {
            $product->setIsFeatured($input->is_featured);
        }
        if ($input->is_new !== null) {
            $product->setIsNew($input->is_new);
        }
        if ($input->is_hot !== null) {
            $product->setIsHot($input->is_hot);
        }
        if ($input->is_sale !== null) {
            $product->setIsSale($input->is_sale);
        }
        if ($input->requires_extra_msmt !== null) {
            $product->setRequiresExtraMsmt($input->requires_extra_msmt);
        }
        if ($input->extra_msmt !== null) {
            $product->setExtraMsmt($input->extra_msmt);
        }
        if ($input->delivery_info !== null) {
            $product->setDeliveryInfo($input->normalizedDeliveryInfo());
        }
        if ($input->status !== null) {
            $product->setStatus($input->status);
        }
        if ($applyCollection && $input->collection_id !== null) {
            $product->setCollectionId($input->collection_id);
        }
        if ($labelId !== null) {
            $product->setLabelId($labelId);
        }
        if ($category !== null) {
            $product->setCategory($category);
        }
    }

    /** Money/decimal columns are stored as 2-dp strings. */
    private static function decimal(int|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
