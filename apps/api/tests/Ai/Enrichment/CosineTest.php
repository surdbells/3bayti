<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Enrichment;

use Bayti\Api\Ai\Enrichment\Cosine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cosine::class)]
final class CosineTest extends TestCase
{
    #[Test]
    public function identicalVectorsScoreOneAndOrthogonalScoreZero(): void
    {
        self::assertEqualsWithDelta(1.0, Cosine::similarity([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
        self::assertEqualsWithDelta(0.0, Cosine::similarity([1.0, 0.0], [0.0, 1.0]), 1e-9);
        self::assertSame(0.0, Cosine::similarity([], [1.0]));
        self::assertSame(0.0, Cosine::similarity([0.0, 0.0], [1.0, 1.0]));
    }

    #[Test]
    public function ranksCandidatesByClosenessAndDropsEmptyVectors(): void
    {
        $query = [1.0, 0.0];
        $candidates = [
            10 => [0.9, 0.1],   // closest
            20 => [0.2, 0.9],   // far
            30 => [0.7, 0.2],   // middle
            40 => [],           // no embedding — dropped
        ];

        self::assertSame([10, 30, 20], Cosine::rank($query, $candidates, 5));
        self::assertSame([10, 30], Cosine::rank($query, $candidates, 2));
        self::assertSame([], Cosine::rank([], $candidates, 5));
    }
}
