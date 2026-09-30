<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Common;

use Bayti\Api\Domain\Common\SlugHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlugHelper::class)]
final class SlugHelperTest extends TestCase
{
    #[Test]
    public function slugifyHandlesAsciiBasics(): void
    {
        self::assertSame('my-cool-product', SlugHelper::slugify('My Cool Product!'));
        self::assertSame('two-spaces', SlugHelper::slugify('TWO   spaces'));
        self::assertSame('cafe', SlugHelper::slugify('café'));
        self::assertSame('', SlugHelper::slugify('!!!'));
        self::assertSame('', SlugHelper::slugify(''));
    }

    #[Test]
    public function generateUniqueReturnsTheBaseSlugWhenFree(): void
    {
        self::assertSame('almas-fashion', SlugHelper::generateUnique('Almas Fashion', static fn (): bool => false));
    }

    #[Test]
    public function generateUniqueAppendsSuffixOnCollision(): void
    {
        $taken = ['store' => true, 'store-2' => true];
        self::assertSame(
            'store-3',
            SlugHelper::generateUnique('store', static fn (string $s): bool => isset($taken[$s])),
        );
    }

    #[Test]
    public function generateUniqueFallsBackInsteadOfThrowingOnAnUnslugifiableName(): void
    {
        // Symbols-only slugifies to '' regardless of the intl extension — the
        // exact empty-slug path that used to throw a 500 (Sentry PHP-2H). It
        // must use the caller-supplied fallback instead.
        self::assertSame(
            'store-32',
            SlugHelper::generateUnique('!!!', static fn (): bool => false, 'store-32'),
        );
    }

    #[Test]
    public function generateUniqueUsesAGenericDefaultWhenNoUsableFallback(): void
    {
        self::assertSame('item', SlugHelper::generateUnique('###', static fn (): bool => false));
        self::assertSame('item', SlugHelper::generateUnique('###', static fn (): bool => false, '@@@'));
    }

    #[Test]
    public function generateUniqueNeverThrowsForAnArabicOnlyName(): void
    {
        // The reported crash: approving a vendor whose business name is Arabic
        // only. With intl it transliterates to a readable Latin slug; without
        // it, the fallback applies. Either way: non-empty slug, no exception.
        $slug = SlugHelper::generateUnique('لمعة الدجى للازياء', static fn (): bool => false, 'store-32');
        self::assertNotSame('', $slug);
    }
}
