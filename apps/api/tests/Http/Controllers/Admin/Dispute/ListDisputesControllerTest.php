<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\Order\OrderDisputeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Dispute\ListDisputesController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ListDisputesController::class)]
final class ListDisputesControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsEmptyListWhenNoDisputes(): void
    {
        $admin = $this->makeAdminUser(99);

        $repo = $this->createMock(OrderDisputeRepository::class);
        $repo->method('paginated')->willReturn([[], 0]);

        $this->bindEnv($admin, $repo);

        $response = $this->makeGet($admin, '/v3/admin/disputes');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame([], $body['disputes']);
        self::assertSame(0, $body['pagination']['total']);
    }

    #[Test]
    public function returnsDisputesNewestFirst(): void
    {
        $admin = $this->makeAdminUser(99);

        $d1 = $this->makeDispute(id: 1, providerOrderRef: 'NOON-REF-A');
        $d2 = $this->makeDispute(id: 2, providerOrderRef: 'NOON-REF-B');

        $repo = $this->createMock(OrderDisputeRepository::class);
        $repo->method('paginated')->willReturn([[$d1, $d2], 2]);

        $this->bindEnv($admin, $repo);

        $response = $this->makeGet($admin, '/v3/admin/disputes');
        $body = $this->jsonBody($response);

        self::assertCount(2, $body['disputes']);
        self::assertSame(1, $body['disputes'][0]['id']);
        self::assertSame('NOON-REF-A', $body['disputes'][0]['provider_order_ref']);
        self::assertSame(OrderDispute::STATUS_OPEN, $body['disputes'][0]['status']);
    }

    #[Test]
    public function statusFilterIsPassedToRepository(): void
    {
        $admin = $this->makeAdminUser(99);

        $repo = $this->createMock(OrderDisputeRepository::class);
        $repo->expects(self::once())
            ->method('paginated')
            ->with(10, 0, OrderDispute::STATUS_OPEN)
            ->willReturn([[], 0]);

        $this->bindEnv($admin, $repo);
        $response = $this->makeGet($admin, '/v3/admin/disputes?status=open');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function invalidStatusFilterIsDropped(): void
    {
        $admin = $this->makeAdminUser(99);

        $repo = $this->createMock(OrderDisputeRepository::class);
        $repo->expects(self::once())
            ->method('paginated')
            ->with(10, 0, null) // bogus status normalised to null
            ->willReturn([[], 0]);

        $this->bindEnv($admin, $repo);
        $response = $this->makeGet($admin, '/v3/admin/disputes?status=garbage');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function paginationClampsLimit(): void
    {
        $admin = $this->makeAdminUser(99);

        $repo = $this->createMock(OrderDisputeRepository::class);
        $repo->expects(self::once())
            ->method('paginated')
            ->with(100, 50, null) // limit clamped from 9999 to 100
            ->willReturn([[], 0]);

        $this->bindEnv($admin, $repo);
        $response = $this->makeGet($admin, '/v3/admin/disputes?limit=9999&offset=50');
        self::assertSame(200, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function bindEnv(User $admin, OrderDisputeRepository $repo): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OrderDispute::class, $repo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeAdminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    private function makeDispute(int $id, string $providerOrderRef): OrderDispute
    {
        $d = new OrderDispute(
            providerOrderRef: $providerOrderRef,
            eventType: 'CHARGEBACK_OPENED',
            rawEvent: ['eventType' => 'CHARGEBACK_OPENED', 'orderId' => $providerOrderRef],
        );
        $ref = new \ReflectionProperty(OrderDispute::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($d, $id);
        return $d;
    }
}
