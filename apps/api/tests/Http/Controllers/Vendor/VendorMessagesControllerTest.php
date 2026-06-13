<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMessage;
use Bayti\Api\Domain\Catalog\VendorMessageRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\VendorMessagesController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for the vendor message inbox
 * (GET /v3/vendor/messages, POST /v3/vendor/messages/{id}/read).
 */
#[CoversClass(VendorMessagesController::class)]
final class VendorMessagesControllerTest extends HttpTestCase
{
    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $v->approve();
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function makeMessage(int $id, Vendor $vendor, bool $read = false): VendorMessage
    {
        $m = new VendorMessage($vendor, null, "Body {$id}", "Subject {$id}");
        $rp = new \ReflectionProperty($m, 'id');
        $rp->setAccessible(true);
        $rp->setValue($m, $id);
        if ($read) {
            $m->markRead();
        }
        return $m;
    }

    /**
     * @param list<VendorMessage> $messages
     */
    private function bindDeps(User $user, Vendor $vendor, array $messages): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([(int) $vendor->getId()]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturn($vendor);

        $byId = [];
        foreach ($messages as $m) {
            $byId[(int) $m->getId()] = $m;
        }

        $msgRepo = $this->createMock(VendorMessageRepository::class);
        $msgRepo->method('findForVendorPaginated')->willReturn([
            'items' => $messages, 'total' => count($messages),
        ]);
        $msgRepo->method('countUnreadForVendor')->willReturnCallback(
            fn () => count(array_filter($messages, fn (VendorMessage $m) => !$m->isRead())),
        );
        $msgRepo->method('findOneForVendor')->willReturnCallback(
            fn (int $id, int $vid) => $byId[$id] ?? null,
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $msgRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [VendorMessage::class, $msgRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function call(User $user, string $method, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function listReturnsMessagesWithUnreadCount(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, [
            $this->makeMessage(1, $vendor, read: false),
            $this->makeMessage(2, $vendor, read: true),
        ]);

        $res = $this->call($user, 'GET', '/v3/vendor/messages');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertCount(2, $body['data']);
        self::assertSame(1, $body['meta']['unread']);
        self::assertSame('Subject 1', $body['data'][0]['subject']);
    }

    #[Test]
    public function markReadMarksTheMessage(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $msg = $this->makeMessage(7, $vendor, read: false);
        $this->bindDeps($user, $vendor, [$msg]);

        $res = $this->call($user, 'POST', '/v3/vendor/messages/7/read');
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->jsonBody($res)['data']['is_read']);
        self::assertTrue($msg->isRead());
    }

    #[Test]
    public function markReadMissingReturns404(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, []);

        $res = $this->call($user, 'POST', '/v3/vendor/messages/999/read');
        self::assertSame(404, $res->getStatusCode());
    }
}
