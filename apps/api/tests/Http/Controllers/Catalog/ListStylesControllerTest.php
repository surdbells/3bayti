<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Http\Controllers\Catalog\ListStylesController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for GET /v3/styles.
 *
 * Verifies:
 *   - Default type=community when ?type absent
 *   - ?type=editorial routes to the editorial smallint enum
 *   - Unknown ?type falls back to community (resilience)
 *   - Bulk product load is called with the page's style ids
 *   - Embedded products render via the serializer
 *   - Pagination params clamp + thread through
 *   - style_type renders as string label ('community' / 'editorial')
 */
#[CoversClass(ListStylesController::class)]
final class ListStylesControllerTest extends HttpTestCase
{
    #[Test]
    public function defaultsToCommunityTypeWhenUnspecified(): void
    {
        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->expects(self::once())
            ->method('findActivePaginatedByType')
            ->with(Style::TYPE_COMMUNITY, 10, 0)
            ->willReturn(['items' => [], 'total' => 0]);
        $styleRepo->method('loadProductsForStyles')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routesEditorialTypeToEditorialEnum(): void
    {
        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->expects(self::once())
            ->method('findActivePaginatedByType')
            ->with(Style::TYPE_EDITORIAL, 10, 0)
            ->willReturn(['items' => [], 'total' => 0]);
        $styleRepo->method('loadProductsForStyles')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles?type=editorial'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function unknownTypeFallsBackToCommunity(): void
    {
        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->expects(self::once())
            ->method('findActivePaginatedByType')
            ->with(Style::TYPE_COMMUNITY, 10, 0)
            ->willReturn(['items' => [], 'total' => 0]);
        $styleRepo->method('loadProductsForStyles')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles?type=garbage'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function clampsLimitAndOffset(): void
    {
        $styleRepo = $this->createMock(StyleRepository::class);
        // limit=999 should clamp to 50; offset=-3 should clamp to 0.
        $styleRepo->expects(self::once())
            ->method('findActivePaginatedByType')
            ->with(Style::TYPE_COMMUNITY, 50, 0)
            ->willReturn(['items' => [], 'total' => 0]);
        $styleRepo->method('loadProductsForStyles')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/styles?limit=999&offset=-3'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function bulkLoadsProductsForReturnedStyleIds(): void
    {
        $style1 = $this->makeStyleWithInternalId('eid-look', 'Eid Look', Style::TYPE_COMMUNITY, 11);
        $style2 = $this->makeStyleWithInternalId('office-fit', 'Office Fit', Style::TYPE_EDITORIAL, 12);

        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->method('findActivePaginatedByType')
            ->willReturn(['items' => [$style1, $style2], 'total' => 2]);
        // The bulk loader gets BOTH style ids in one call (no N+1).
        $styleRepo->expects(self::once())
            ->method('loadProductsForStyles')
            ->with([11, 12])
            ->willReturn([
                11 => [
                    [
                        'id' => 100, 'slug' => 'p100', 'name' => 'P100',
                        'primary_image_url' => 'http://img/p100.jpg',
                        'price' => '199.00', 'display_order' => 0,
                    ],
                ],
                12 => [], // empty products is allowed
            ]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['data']);
        // First style has one product embedded.
        self::assertSame('eid-look', $body['data'][0]['slug']);
        self::assertCount(1, $body['data'][0]['products']);
        self::assertSame(100, $body['data'][0]['products'][0]['id']);
        // Style type rendered as string label.
        self::assertSame('community', $body['data'][0]['style_type']);
        // Second style has empty products list.
        self::assertSame('editorial', $body['data'][1]['style_type']);
        self::assertSame([], $body['data'][1]['products']);
    }

    #[Test]
    public function rendersStyleTypeAsStringLabel(): void
    {
        $style = $this->makeStyleWithInternalId('look', 'A Look', Style::TYPE_EDITORIAL, 1);

        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->method('findActivePaginatedByType')
            ->willReturn(['items' => [$style], 'total' => 1]);
        $styleRepo->method('loadProductsForStyles')->willReturn([1 => []]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles?type=editorial'));

        $body = $this->jsonBody($response);
        self::assertSame('editorial', $body['data'][0]['style_type']);
    }

    #[Test]
    public function passesThroughTotalForPaginationMeta(): void
    {
        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->method('findActivePaginatedByType')
            ->willReturn(['items' => [], 'total' => 137]);
        $styleRepo->method('loadProductsForStyles')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Style::class)->willReturn($styleRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/styles'));
        $body = $this->jsonBody($response);
        self::assertSame(137, $body['meta']['total']);
    }

    private function makeStyleWithInternalId(string $slug, string $name, int $type, int $id): Style
    {
        $style = new Style($slug, $name, $type);
        $style->setTotalPrice('0.00');
        $ref = new \ReflectionProperty(Style::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($style, $id);
        return $style;
    }
}
