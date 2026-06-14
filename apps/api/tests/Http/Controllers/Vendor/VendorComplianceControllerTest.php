<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
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
 * v3 vendor compliance (KYC) — documents are stored as PRIVATE files (the
 * vendor row holds the path); GET reads them back as data URLs, PATCH
 * stores new uploads and marks 'submitted'.
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

        $docs = $this->createMock(ComplianceDocumentService::class);
        $docs->method('store')->willReturnCallback(
            static fn (int $vid, string $type, string $url): string => "compliance/vendor-{$vid}/{$type}-x.png"
        );
        $docs->method('readAsDataUrl')->willReturnCallback(
            static fn (?string $path): ?string => $path === null ? null : 'data:image/png;base64,AAAA'
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(ComplianceDocumentService::class, $docs);
    }

    private function token(User $user): string
    {
        return $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user)->accessToken;
    }

    #[Test]
    public function getReturnsDocumentUrlsAndStatus(): void
    {
        $user = $this->vendorUser(100);
        $vendor = $this->vendor(101);
        $vendor->submitCompliance('compliance/vendor-101/front-x.png', null, null);
        $this->bindDeps($user, $vendor);

        $res = $this->handle($this->jsonRequest('GET', '/v3/vendor/compliance', [], [
            'Authorization' => 'Bearer ' . $this->token($user),
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertStringContainsString('/v3/compliance-documents/101/front?', $data['front']);
        self::assertNull($data['back']);
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
            'license_doc' => 'assets/img/placeholder-1.png',
        ], ['Authorization' => 'Bearer ' . $this->token($user)]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertTrue($data['has_front']);
        self::assertTrue($data['has_back']);
        self::assertFalse($data['has_license_doc']);
        self::assertNotNull($this->saved);
        self::assertSame('compliance/vendor-101/front-x.png', $this->saved->getIdFront());
        self::assertSame('compliance/vendor-101/back-x.png', $this->saved->getIdBack());
        self::assertNull($this->saved->getLicenseDoc());
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
