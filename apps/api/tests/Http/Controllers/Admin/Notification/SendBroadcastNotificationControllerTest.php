<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Notification\SendBroadcastNotificationController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Notification\Push\InMemoryPushSender;
use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for the admin push broadcast (POST /v3/admin/notifications):
 *
 *   - Fans out to every active token and reports a {sent, failed} summary.
 *   - Forwards the chosen audience to the token query.
 *   - Isolates per-token failures (one bad token doesn't abort the run)
 *     and deactivates UNREGISTERED tokens.
 *   - Validates title/body/audience.
 *   - Requires admin tier (403 non-admin, 401 unauthenticated).
 */
#[CoversClass(SendBroadcastNotificationController::class)]
final class SendBroadcastNotificationControllerTest extends HttpTestCase
{
    private InMemoryPushSender $push;
    /** @var list<string> tokens deactivated by the controller */
    private array $deactivated = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->push = new InMemoryPushSender();
        $this->deactivated = [];
        $this->bind(PushSenderInterface::class, $this->push);
    }

    private function makeAdmin(int $id = 99): User
    {
        $admin = $this->makeUser(id: $id, email: 'admin@bayti.example');
        $admin->setRoles(admin: true);
        return $admin;
    }

    /**
     * Bind an EM whose DeviceTokenRepository returns the given tokens and
     * records deactivations. The admin caller is resolved by AuthMiddleware.
     *
     * @param list<string> $tokens
     */
    private function bindEm(User $caller, array $tokens, string $expectAudience = 'all'): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($caller);

        $deviceRepo = $this->createMock(DeviceTokenRepository::class);
        $deviceRepo->method('findAllActiveTokenStrings')
            ->with($expectAudience)
            ->willReturn($tokens);
        $deviceRepo->method('deactivateByToken')->willReturnCallback(
            function (string $t): bool {
                $this->deactivated[] = $t;
                return true;
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $deviceRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [DeviceToken::class, $deviceRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(DeviceTokenRepository::class, $deviceRepo);
    }

    /** @param array<string, mixed> $body */
    private function post(User $user, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', '/v3/admin/notifications', $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function broadcastsToEveryActiveToken(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['tok-a', 'tok-b', 'tok-c']);

        $response = $this->post($admin, [
            'title' => 'Eid sale',
            'body' => '20% off everything this weekend',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertSame(3, $json['data']['recipients']);
        self::assertSame(3, $json['data']['sent']);
        self::assertSame(0, $json['data']['failed']);
        self::assertCount(3, $this->push->sent());
        // Title/body propagate to the PushMessage.
        self::assertSame('Eid sale', $this->push->sent()[0]['message']->title);
    }

    #[Test]
    public function forwardsAudienceToTokenQuery(): void
    {
        $admin = $this->makeAdmin();
        // bindEm asserts findAllActiveTokenStrings was called with 'vendors'.
        $this->bindEm($admin, ['v-1', 'v-2'], expectAudience: 'vendors');

        $response = $this->post($admin, [
            'title' => 'Payout schedule update',
            'body' => 'Payouts now run every Monday',
            'audience' => 'vendors',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertSame('vendors', $json['data']['audience']);
        self::assertSame(2, $json['data']['sent']);
    }

    #[Test]
    public function isolatesFailuresAndDeactivatesUnregisteredTokens(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['good-1', 'dead', 'transient', 'good-2']);
        // 'dead' is permanently invalid → should be deactivated.
        $this->push->failToken('dead', PushException::KIND_UNREGISTERED);
        // 'transient' fails but is NOT unregistered → counted, not pruned.
        $this->push->failToken('transient', 'transport');

        $response = $this->post($admin, [
            'title' => 'Heads up',
            'body' => 'New features are live',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertSame(4, $json['data']['recipients']);
        self::assertSame(2, $json['data']['sent']);   // good-1, good-2
        self::assertSame(2, $json['data']['failed']); // dead, transient
        // Only the unregistered token is deactivated.
        self::assertSame(['dead'], $this->deactivated);
    }

    #[Test]
    public function returns400OnMissingTitle(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['tok-a']);

        $response = $this->post($admin, ['body' => 'no title here']);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, $this->push->sent());
    }

    #[Test]
    public function returns400OnMissingBody(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['tok-a']);

        $response = $this->post($admin, ['title' => 'no body here']);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, $this->push->sent());
    }

    #[Test]
    public function returns400OnInvalidAudience(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['tok-a']);

        $response = $this->post($admin, [
            'title' => 'Hi',
            'body' => 'There',
            'audience' => 'martians',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(0, $this->push->sent());
    }

    #[Test]
    public function forbiddenForNonAdmin(): void
    {
        $nonAdmin = $this->makeUser(id: 7, email: 'shopper@bayti.example');
        $this->bindEm($nonAdmin, ['tok-a']);

        $response = $this->post($nonAdmin, ['title' => 'Hi', 'body' => 'There']);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->push->sent());
    }

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEm($admin, ['tok-a']);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/admin/notifications', ['title' => 'Hi', 'body' => 'There'])
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, $this->push->sent());
    }
}
