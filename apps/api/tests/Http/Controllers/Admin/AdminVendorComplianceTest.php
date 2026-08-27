<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\ApproveVendorComplianceController;
use Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\GetAdminVendorComplianceController;
use Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\RejectVendorComplianceController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Admin KYC compliance review, view a submission, approve, reject.
 */
#[CoversClass(GetAdminVendorComplianceController::class)]
#[CoversClass(ApproveVendorComplianceController::class)]
#[CoversClass(RejectVendorComplianceController::class)]
final class AdminVendorComplianceTest extends HttpTestCase
{
    private function adminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    private function vendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $v->approve();
        $v->submitCompliance('compliance/vendor-' . $id . '/front-x.png', null, null);
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function bindDeps(User $admin, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn($vendor);

        $docs = $this->createMock(ComplianceDocumentService::class);
        $docs->method('readAsDataUrl')->willReturnCallback(
            static fn (?string $p): ?string => $p === null ? null : 'data:image/png;base64,AAAA'
        );

        $notifier = $this->createMock(\Bayti\Api\Domain\Compliance\ComplianceNotificationService::class);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(ComplianceDocumentService::class, $docs);
        $this->bind(\Bayti\Api\Domain\Compliance\ComplianceNotificationService::class, $notifier);
    }

    private function token(User $u): string
    {
        return $this->app->getContainer()->get(JwtService::class)->issueTokenPair($u)->accessToken;
    }

    #[Test]
    public function getReturnsSubmissionWithDocuments(): void
    {
        $admin = $this->adminUser(1);
        $this->bindDeps($admin, $this->vendor(101));

        $res = $this->handle($this->jsonRequest('GET', '/v3/admin/vendors/101/compliance', [], [
            'Authorization' => 'Bearer ' . $this->token($admin),
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame(101, $data['vendor_id']);
        self::assertStringContainsString('/v3/compliance-documents/101/front?', $data['front']);
        self::assertSame('submitted', $data['compliance_status']);
    }

    #[Test]
    public function approveMarksApprovedWithReviewer(): void
    {
        $admin = $this->adminUser(7);
        $this->bindDeps($admin, $this->vendor(101));

        $res = $this->handle($this->jsonRequest('POST', '/v3/admin/vendors/101/compliance/approve', [], [
            'Authorization' => 'Bearer ' . $this->token($admin),
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame('approved', $data['compliance_status']);
        self::assertNotNull($data['reviewed_at']);
    }

    #[Test]
    public function rejectMarksRejectedWithNote(): void
    {
        $admin = $this->adminUser(7);
        $this->bindDeps($admin, $this->vendor(101));

        $res = $this->handle($this->jsonRequest('POST', '/v3/admin/vendors/101/compliance/reject', [
            'note' => 'ID photo is blurry, please re-upload.',
        ], ['Authorization' => 'Bearer ' . $this->token($admin)]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame('rejected', $data['compliance_status']);
        self::assertStringContainsString('blurry', $data['review_note']);
    }
}
