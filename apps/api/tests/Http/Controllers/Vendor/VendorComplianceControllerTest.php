<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Compliance\GetVendorComplianceController;
use Bayti\Api\Http\Controllers\Vendor\Compliance\UpdateVendorComplianceController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * v3 vendor compliance (KYC) — GET loads the vendor's documents + status;
 * PATCH stores them and moves compliance to 'submitted'. Replaces the
 * legacy session-blob reliance + the misused onboarding submit.
 */
#[CoversClass(GetVendorComplianceController::class)]
#[CoversClass(UpdateVendorComplianceController::class)]
final class VendorComplianceControllerTest extends HttpTestCase
{
    private ?Vendor $saved = null;

    private function vendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function vendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $v->approve();
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function bindDeps(User $user, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);
        $vendorRepo->method('save')->willReturnCallback(function (Vendor $v): void {
            $this->saved = $v;
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function token(User $user): string
    {
        return $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user)->accessToken;
    }

    #[Test]
    public function getReturnsDocumentsAndStatus(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101);
        $vendor->submitCompliance('data:image/png;base64,' . str_repeat('A', 60), null, null);
        $this->bindDeps($user, $vendor);

        $res = $this->handle($this->jsonRequest('GET', '/v3/vendor/compliance', [], [
            'Authorization' => 'Bearer ' . $this->token($user),
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertStringStartsWith('data:image/png', $data['front']);
        self::assertSame('submitted', $data['compliance_status']);
        self::assertTrue($data['is_active']);
    }

    #[Test]
    public function patchStoresDocumentsAndMarksSubmitted(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101);
        $this->bindDeps($user, $vendor);

        $front = 'data:image/png;base64,' . str_repeat('A', 80);
        $back = 'data:image/png;base64,' . str_repeat('B', 80);
        $res = $this->handle($this->jsonRequest('PATCH', '/v3/vendor/compliance', [
            'front' => $front,
            'back' => $back,
            'license_doc' => 'assets/img/placeholder-1.png', // unchanged placeholder → ignored
        ], ['Authorization' => 'Bearer ' . $this->token($user)]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertNotNull($this->saved);
        self::assertSame($front, $this->saved->getIdFront());
        self::assertSame($back, $this->saved->getIdBack());
        self::assertNull($this->saved->getLicenseDoc()); // placeholder ignored
        self::assertSame('submitted', $this->saved->getComplianceStatus());
    }

    #[Test]
    public function patchRejectsWhenNoDocuments(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101);
        $this->bindDeps($user, $vendor);

        $res = $this->handle($this->jsonRequest('PATCH', '/v3/vendor/compliance', [
            'front' => 'assets/img/placeholder-1.png',
        ], ['Authorization' => 'Bearer ' . $this->token($user)]));

        self::assertSame(400, $res->getStatusCode());
    }
}
