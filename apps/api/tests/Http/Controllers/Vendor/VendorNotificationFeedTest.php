<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Notification\ListVendorNotificationsController;
use Bayti\Api\Http\Controllers\Vendor\Notification\MarkVendorNotificationsReadController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The v3 vendor notification feed (NotificationLog-backed) that replaces
 * the legacy /vendors/common/notifications bell calls.
 */
#[CoversClass(ListVendorNotificationsController::class)]
#[CoversClass(MarkVendorNotificationsReadController::class)]
final class VendorNotificationFeedTest extends HttpTestCase
{
    private function vendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function vendor(int $id, string $email): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", $email);
        $v->approve();
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function log(int $id, string $template, ?int $orderId, bool $read): NotificationLog
    {
        $log = NotificationLog::sent($orderId, $template, 'store@example.com');
        if ($read) {
            $log->markRead();
        }
        $rp = new \ReflectionProperty($log, 'id');
        $rp->setAccessible(true);
        $rp->setValue($log, $id);
        return $log;
    }

    /** @param array{items: list<NotificationLog>, total: int, unread: int} $feed */
    private function bindDeps(User $user, Vendor $vendor, array $feed, int &$markedRef): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);

        $notifRepo = $this->createMock(NotificationLogRepository::class);
        $notifRepo->method('findFeed')->willReturn($feed);
        $notifRepo->method('markFeedRead')->willReturnCallback(
            function (array $recipients, ?array $ids, ?string $tpl) use (&$markedRef): int {
                return $markedRef;
            }
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $notifRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [NotificationLog::class, $notifRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    #[Test]
    public function listsFeedWithReadableMessagesAndUnreadMeta(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101, 'store@example.com');
        $feed = [
            'items' => [
                $this->log(5, 'order.placed.vendor', 900, false),
                $this->log(4, 'return.submitted.vendor', 901, true),
            ],
            'total' => 2,
            'unread' => 1,
        ];
        $unused = 0;
        $this->bindDeps($user, $vendor, $feed, $unused);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $token = $jwt->issueTokenPair($user)->accessToken;

        $res = $this->handle($this->jsonRequest('GET', '/v3/vendor/notifications', [], [
            'Authorization' => 'Bearer ' . $token,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertCount(2, $body['data']);
        self::assertSame(1, $body['meta']['unread']);
        self::assertStringContainsString('new sale', strtolower($body['data'][0]['message']));
        self::assertStringContainsString('900', $body['data'][0]['message']);
        self::assertFalse($body['data'][0]['is_read']);
        self::assertTrue($body['data'][1]['is_read']);
    }

    #[Test]
    public function markReadReturnsCount(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101, 'store@example.com');
        $marked = 3;
        $this->bindDeps($user, $vendor, ['items' => [], 'total' => 0, 'unread' => 0], $marked);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $token = $jwt->issueTokenPair($user)->accessToken;

        $res = $this->handle($this->jsonRequest('POST', '/v3/vendor/notifications/mark-read', [], [
            'Authorization' => 'Bearer ' . $token,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(3, $this->jsonBody($res)['data']['marked']);
    }
}
