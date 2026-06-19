<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMessage;
use Bayti\Api\Domain\Catalog\VendorMessageRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\ListVendorMessagesController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/** HTTP tests for GET /v3/admin/vendors/{id}/messages. */
#[CoversClass(ListVendorMessagesController::class)]
final class ListVendorMessagesControllerTest extends HttpTestCase
{
    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($v, $id);
        return $v;
    }

    private function makeMessage(int $id, Vendor $vendor): VendorMessage
    {
        $m = new VendorMessage($vendor, null, "Body {$id}", "Subject {$id}");
        $ref = new \ReflectionProperty($m, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, $id);
        return $m;
    }

    /** @param list<VendorMessage> $messages */
    private function bindDeps(User $admin, ?Vendor $vendor, array $messages): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

        $msgRepo = $this->createMock(VendorMessageRepository::class);
        $msgRepo->method('findForVendorPaginated')->willReturn([
            'items' => $messages, 'total' => count($messages),
        ]);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $msgRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [VendorMessage::class, $msgRepo],
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

    #[Test]
    public function listsVendorMessages(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($admin, $vendor, [
            $this->makeMessage(1, $vendor),
            $this->makeMessage(2, $vendor),
        ]);

        $res = $this->makeGet($admin, '/v3/admin/vendors/101/messages');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['meta']['total']);
        self::assertSame('Subject 1', $body['data'][0]['subject']);
        self::assertArrayHasKey('is_read', $body['data'][0]);
    }

    #[Test]
    public function returns404ForUnknownVendor(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, null, []);

        $res = $this->makeGet($admin, '/v3/admin/vendors/999/messages');
        self::assertSame(404, $res->getStatusCode());
    }
}
