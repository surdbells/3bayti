<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Analytics;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Analytics\GetAdminAiAnalyticsController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for GET /v3/admin/ai/analytics.
 *
 * The controller aggregates over ai_interactions / ai_events with raw DBAL, so
 * the Connection is mocked and routed by SQL fragment (the same posture as the
 * concierge pipeline tests) — the point under test is the payload assembly, the
 * rate maths, and the admin gate, not Postgres itself.
 */
#[CoversClass(GetAdminAiAnalyticsController::class)]
final class GetAdminAiAnalyticsControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsFunnelTotalsRatesAndAttributedRevenue(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin);

        $response = $this->makeGet($admin, '/v3/admin/ai/analytics?days=30');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // Responder::ok returns the raw body (no {data} envelope).
        $data = $this->jsonBody($response);

        // Totals surfaced from the funnel + attribution queries.
        self::assertSame(10, $data['totals']['queries']);
        self::assertSame(3, $data['totals']['unique_users']);
        self::assertSame(8, $data['totals']['recommendations_shown']);
        self::assertSame(4, $data['totals']['product_clicks']);
        self::assertSame(2, $data['totals']['add_to_cart']);
        self::assertSame(3, $data['totals']['ai_assisted_orders']);
        self::assertSame(1500.5, $data['totals']['ai_assisted_revenue']);

        // Rates computed against the right denominators.
        self::assertSame(0.4, $data['rates']['click_through_per_query']);
        self::assertSame(0.2, $data['rates']['add_to_cart_per_query']);
        self::assertSame(0.5, $data['rates']['click_through_per_recommendation']);

        // Intents + most-recommended pass through.
        self::assertSame('abaya', $data['top_product_types'][0]['label']);
        self::assertSame('wedding', $data['top_occasions'][0]['label']);
        self::assertSame(7, $data['most_recommended'][0]['product_id']);
        self::assertSame('Black Abaya', $data['most_recommended'][0]['name']);

        self::assertSame(7, $data['attribution_window_days']);
        self::assertSame(30, $data['range_days']);
        self::assertNotEmpty($data['query_series']);
    }

    #[Test]
    public function ratesAreZeroWhenThereAreNoQueries(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, empty: true);

        $response = $this->makeGet($admin, '/v3/admin/ai/analytics');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = $this->jsonBody($response);
        self::assertSame(0, $data['totals']['queries']);
        self::assertSame(0.0, $data['rates']['click_through_per_query']);
        self::assertSame(0.0, $data['rates']['add_to_cart_per_query']);
    }

    #[Test]
    public function requiresAdmin(): void
    {
        $user = $this->makeUser(id: 200); // no admin role → ai.view denied
        $this->bindDeps($user);

        $response = $this->makeGet($user, '/v3/admin/ai/analytics');
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    // ===== helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function bindDeps(User $user, bool $empty = false): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $conn = $this->cannedConnection($empty);

        $em = $this->stubEm(function ($em) use ($userRepo, $conn): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
            $em->method('getConnection')->willReturn($conn);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function cannedConnection(bool $empty): Connection
    {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchOne')->willReturnCallback(function (string $sql) use ($empty): int {
            if ($empty) {
                return 0;
            }
            if (str_contains($sql, 'COUNT(DISTINCT user_id)')) {
                return 3;
            }
            if (str_contains($sql, 'COUNT(DISTINCT session_id)')) {
                return 5;
            }
            return 10; // COUNT(*) FROM ai_interactions
        });

        $conn->method('fetchAssociative')->willReturnCallback(function (string $sql) use ($empty): array {
            if ($empty) {
                return ['orders' => 0, 'revenue' => 0];
            }
            // attribution CTE
            return ['orders' => 3, 'revenue' => 1500.5];
        });

        $conn->method('fetchAllAssociative')->willReturnCallback(function (string $sql) use ($empty): array {
            if ($empty) {
                return [];
            }
            if (str_contains($sql, 'GROUP BY event')) {
                return [
                    ['event' => 'ai_opened', 'c' => 12],
                    ['event' => 'ai_product_recommendation_displayed', 'c' => 8],
                    ['event' => 'ai_product_clicked', 'c' => 4],
                    ['event' => 'ai_product_added_to_cart', 'c' => 2],
                ];
            }
            if (str_contains($sql, "intent->>'product_type'")) {
                return [['label' => 'abaya', 'c' => 6], ['label' => 'kaftan', 'c' => 2]];
            }
            if (str_contains($sql, "intent->'occasions'")) {
                return [['label' => 'wedding', 'c' => 4], ['label' => 'eid', 'c' => 3]];
            }
            if (str_contains($sql, "intent->'colours'")) {
                return [['label' => 'black', 'c' => 5]];
            }
            if (str_contains($sql, 'JOIN products p')) {
                return [
                    ['product_id' => 7, 'name' => 'Black Abaya', 'slug' => 'black-abaya', 'count' => 9],
                    ['product_id' => 3, 'name' => 'Beige Abaya', 'slug' => 'beige-abaya', 'count' => 4],
                ];
            }
            // daily query series
            return [['d' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'), 'v' => 4]];
        });

        return $conn;
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
