<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Product::setStatus alias normalisation (bug 4): the publish flow and
 * other clients send 'published'/'inactive', which must map to the
 * canonical active/soft_deleted rather than crash.
 */
#[CoversClass(Product::class)]
final class ProductStatusTest extends TestCase
{
    private function product(): Product
    {
        $vendor = new Vendor('v', 'V', 'v@example.com');
        return new Product($vendor, 'p', 'P');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function statusCases(): array
    {
        return [
            'published → active'   => ['published', Product::STATUS_ACTIVE, true],
            'active passthrough'   => ['active', Product::STATUS_ACTIVE, true],
            'draft passthrough'    => ['draft', Product::STATUS_DRAFT, false],
            'inactive → soft_del'  => ['inactive', Product::STATUS_SOFT_DELETED, false],
            'soft_deleted direct'  => ['soft_deleted', Product::STATUS_SOFT_DELETED, false],
        ];
    }

    #[Test]
    #[DataProvider('statusCases')]
    public function normalisesStatusAliases(string $input, string $expected, bool $expectActive): void
    {
        $p = $this->product();
        $p->setStatus($input);
        self::assertSame($expected, $p->getStatus());
        self::assertSame($expectActive, $p->isActive());
    }

    #[Test]
    public function rejectsTrulyInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->product()->setStatus('archived');
    }
}
