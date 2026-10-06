<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Join row linking a curated PRODUCT to a COLLECTION (the many-to-many the
 * ProductCollection docblock always assumed but was never built). Admin
 * curation writes these rows; the storefront "products by collection" filter
 * and the home-card representative image read them.
 *
 * Distinct from the legacy single `products.collection_id` scalar (a vendor
 * self-tag). This table is the ADMIN-curated membership and is the source of
 * truth for the storefront. A product can belong to many collections.
 *
 * sort_order is the admin's curation order within the collection: position 0
 * is the "first product", whose image represents the collection on cards.
 * Both FK sides cascade-delete so removing a collection or a product cleans
 * up its membership rows automatically.
 */
#[ORM\Entity(repositoryClass: CollectionProductRepository::class)]
#[ORM\Table(name: 'collection_products')]
#[ORM\UniqueConstraint(name: 'uq_collection_products', columns: ['collection_id', 'product_id'])]
#[ORM\Index(columns: ['collection_id', 'sort_order'], name: 'idx_collection_products_order')]
#[ORM\Index(columns: ['product_id'], name: 'idx_collection_products_product')]
class CollectionProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCollection::class)]
    #[ORM\JoinColumn(name: 'collection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCollection $collection;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(ProductCollection $collection, Product $product, int $sortOrder = 0)
    {
        $this->collection = $collection;
        $this->product = $product;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCollection(): ProductCollection { return $this->collection; }
    public function getProduct(): Product { return $this->product; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $order): void { $this->sortOrder = $order; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
