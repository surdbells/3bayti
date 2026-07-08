<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Shared DTO for vendor product create + update (M3.3.1-C).
 * All fields are nullable so the same DTO works for both full-create
 * and partial-update; individual controllers enforce required fields.
 */
final class VendorProductInput
{
    #[Assert\Length(max: 255, maxMessage: 'Name must be 255 characters or fewer.')]
    public readonly ?string $name;

    #[Assert\Length(max: 5000, maxMessage: 'Description must be 5000 characters or fewer.')]
    public readonly ?string $description;

    #[Assert\PositiveOrZero(message: 'Price must be zero or positive.')]
    public readonly int|float|null $price;

    /** Optional discounted price. Rendered as on-sale when set and below price. */
    #[Assert\PositiveOrZero(message: 'Sale price must be zero or positive.')]
    public readonly int|float|null $sale_price;

    #[Assert\PositiveOrZero(message: 'Cost per item must be zero or positive.')]
    public readonly int|float|null $cost_per_item;

    #[Assert\Choice(choices: ['in_stock', 'out_of_stock', 'backorder'],
        message: 'stock_status must be in_stock, out_of_stock, or backorder.')]
    public readonly ?string $stock_status;

    #[Assert\PositiveOrZero(message: 'Stock quantity must be zero or positive.')]
    public readonly ?int $stock_quantity;

    public readonly ?bool $allow_oversell;

    #[Assert\Positive(message: 'min_order_qty must be a positive integer.')]
    public readonly ?int $min_order_qty;

    #[Assert\Positive(message: 'max_order_qty must be a positive integer.')]
    public readonly ?int $max_order_qty;

    /** URL of the primary (hero) product image. */
    #[Assert\Length(max: 1000, maxMessage: 'primary_image_url must be 1000 characters or fewer.')]
    public readonly ?string $primary_image_url;

    /** Additional image URLs (up to 5 supported).
     * @var list<string>|null
     */
    #[Assert\All([new Assert\Length(max: 1000)])]
    public readonly ?array $image_urls;

    /** Available size labels (e.g. ['S', 'M', 'L', '38', '39']). */
    /** @var list<string>|null */
    public readonly ?array $sizes;

    /** Available colour names (e.g. ['black', 'white', 'ivory']). */
    /** @var list<string>|null */
    public readonly ?array $colors;

    /** Category ID (integer FK). */
    #[Assert\Positive(message: 'category_id must be a positive integer.')]
    public readonly ?int $category_id;

    #[Assert\Positive(message: 'collection_id must be a positive integer.')]
    public readonly ?int $collection_id;

    #[Assert\Positive(message: 'label_id must be a positive integer.')]
    public readonly ?int $label_id;

    #[Assert\Choice(
        choices: ['draft', 'active', 'inactive', 'published'],
        message: 'status must be draft, active, inactive, or published.',
    )]
    public readonly ?string $status;

    public readonly ?bool $is_featured;
    public readonly ?bool $is_new;
    public readonly ?bool $is_hot;
    public readonly ?bool $is_sale;
    public readonly ?bool $requires_extra_msmt;

    #[Assert\Length(max: 1000)]
    public readonly ?string $extra_msmt;

    /**
     * @param list<string>|null $image_urls
     * @param list<string>|null $sizes
     * @param list<string>|null $colors
     */
    public function __construct(
        ?string  $name             = null,
        ?string  $description      = null,
        int|float|null $price      = null,
        int|float|null $sale_price = null,
        int|float|null $cost_per_item = null,
        ?string  $stock_status     = null,
        ?int     $stock_quantity   = null,
        ?bool    $allow_oversell   = null,
        ?int     $min_order_qty    = null,
        ?int     $max_order_qty    = null,
        ?string  $primary_image_url = null,
        ?array   $image_urls       = null,
        ?array   $sizes            = null,
        ?array   $colors           = null,
        ?int     $category_id      = null,
        ?string  $status           = null,
        ?bool    $is_featured      = null,
        ?bool    $is_new           = null,
        ?bool    $is_hot           = null,
        ?bool    $is_sale          = null,
        ?bool    $requires_extra_msmt = null,
        ?string  $extra_msmt       = null,
        ?int     $collection_id    = null,
        ?int     $label_id         = null,
    ) {
        $this->name              = $name;
        $this->description       = $description;
        $this->price             = $price;
        $this->sale_price        = $sale_price;
        $this->cost_per_item     = $cost_per_item;
        $this->stock_status      = $stock_status;
        $this->stock_quantity    = $stock_quantity;
        $this->allow_oversell    = $allow_oversell;
        $this->min_order_qty     = $min_order_qty;
        $this->max_order_qty     = $max_order_qty;
        $this->primary_image_url = $primary_image_url;
        $this->image_urls        = $image_urls;
        $this->sizes             = $sizes;
        $this->colors            = $colors;
        $this->category_id       = $category_id;
        $this->status            = $status;
        $this->is_featured       = $is_featured;
        $this->is_new            = $is_new;
        $this->is_hot            = $is_hot;
        $this->is_sale           = $is_sale;
        $this->requires_extra_msmt = $requires_extra_msmt;
        $this->extra_msmt        = $extra_msmt;
        $this->collection_id     = $collection_id;
        $this->label_id          = $label_id;
    }
}
