<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\Mapping as ORM;

/**
 * Pre-computed per-product recommendation row (M3.2.X.12-B).
 *
 * Each row says "when product X is being viewed, recommend
 * product Y, with score Z and reason 'source'". Rows are
 * populated by the X.12-E cron and queried via single indexed
 * lookups at request time.
 *
 * Source values:
 *   'copurchase'       , Y is recommended because customers
 *                          who bought X also bought Y. Score
 *                          is the co-purchase count.
 *   'category'         , Y is in the same category as X.
 *                          Score is a small constant (e.g. 1.0)
 *                          to keep category fallback ranked
 *                          below co-purchase rows.
 *   'fallback_popular' , Used only when X has no co-purchase
 *                          AND no category matches. Y is the
 *                          marketplace-wide most-popular product.
 *                          Score is the popularity count.
 */
#[ORM\Entity(repositoryClass: ProductRecommendationRepository::class)]
#[ORM\Table(name: 'product_recommendations')]
#[ORM\UniqueConstraint(name: 'uniq_product_recs_pair', columns: ['product_id', 'recommended_product_id'])]
#[ORM\Index(name: 'idx_product_recs_lookup', columns: ['product_id', 'rank'])]
#[ORM\Index(name: 'idx_product_recs_source', columns: ['source'])]
class ProductRecommendation
{
    public const SOURCE_COPURCHASE = 'copurchase';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_FALLBACK_POPULAR = 'fallback_popular';

    /** @var list<string> */
    public const VALID_SOURCES = [
        self::SOURCE_COPURCHASE,
        self::SOURCE_CATEGORY,
        self::SOURCE_FALLBACK_POPULAR,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line property.unusedType, Doctrine hydrates via reflection */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'recommended_product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $recommendedProduct;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 4)]
    private string $score;

    #[ORM\Column(type: 'string', length: 20)]
    private string $source;

    #[ORM\Column(type: 'integer')]
    private int $rank;

    #[ORM\Column(name: 'computed_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $computedAt;

    public function __construct(
        Product $product,
        Product $recommendedProduct,
        string $score,
        string $source,
        int $rank,
        ?\DateTimeImmutable $computedAt = null,
    ) {
        $this->setProduct($product);
        $this->setRecommendedProduct($recommendedProduct);
        $this->setScore($score);
        $this->setSource($source);
        $this->setRank($rank);
        $this->computedAt = $computedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getRecommendedProduct(): Product
    {
        return $this->recommendedProduct;
    }

    public function setRecommendedProduct(Product $product): void
    {
        // Defensive: a product cannot recommend itself
        if (
            $this->product->getId() !== null
            && $product->getId() !== null
            && $this->product->getId() === $product->getId()
        ) {
            throw new \InvalidArgumentException(
                'A product cannot recommend itself.',
            );
        }
        $this->recommendedProduct = $product;
    }

    public function getScore(): string
    {
        return $this->score;
    }

    public function setScore(string $score): void
    {
        if (!is_numeric($score)) {
            throw new \InvalidArgumentException(
                "Score must be a numeric decimal string, got: {$score}",
            );
        }
        if (bccomp($score, '0', 4) < 0) {
            throw new \InvalidArgumentException(
                "Score must be >= 0, got: {$score}",
            );
        }
        // NUMERIC(8, 4) caps at 9999.9999
        if (bccomp($score, '9999.9999', 4) > 0) {
            throw new \InvalidArgumentException(
                "Score must be <= 9999.9999, got: {$score}",
            );
        }
        $this->score = $score;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        if (!in_array($source, self::VALID_SOURCES, true)) {
            throw new \InvalidArgumentException(
                'Source must be one of: ' . implode(', ', self::VALID_SOURCES)
                . ", got: {$source}",
            );
        }
        $this->source = $source;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): void
    {
        if ($rank < 1) {
            throw new \InvalidArgumentException(
                "Rank must be >= 1, got: {$rank}",
            );
        }
        $this->rank = $rank;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function touchComputedAt(?\DateTimeImmutable $when = null): void
    {
        $this->computedAt = $when ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
