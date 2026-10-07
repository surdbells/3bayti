<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Label\VendorLabelCrudController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET + POST /v3/vendor/labels keep their management shape
 * ({ id, label, name, slug, display_order, is_active }), now produced by the
 * VendorLabelSerializer shared with the admin /v3/admin/vendors/{id}/labels
 * endpoints, and still resolve the store from the caller's own session.
 */
#[CoversClass(VendorLabelCrudController::class)]
final class VendorLabelCrudControllerTest extends HttpTestCase
{
    /** @var list<VendorLabel> */
    private array $saved = [];

    /** @var VendorLabelRepository&\PHPUnit\Framework\MockObject\MockObject */
    private VendorLabelRepository $labelRepo;

    #[Test]
    public function listReturnsTheCallersActiveLabelsInTheManageShape(): void
    {
        [$user, $vendor] = $this->bindVendor();
        $label = new VendorLabel($vendor, 'eid-abc123', 'Eid');
        $label->setDisplayOrder(2);
        $this->forceId($label, 11);
        $this->labelRepo->method('listActiveByVendor')->with($vendor)->willReturn([$label]);

        $res = $this->send($user, 'GET', '/v3/vendor/labels');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([[
            'id' => 11,
            'label' => 'Eid',
            'name' => 'Eid',
            'slug' => 'eid-abc123',
            'display_order' => 2,
            'is_active' => true,
        ]], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function createMakesTheLabelUnderTheCallersStore(): void
    {
        [$user, $vendor] = $this->bindVendor();

        $res = $this->send($user, 'POST', '/v3/vendor/labels', ['label' => 'New In']);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertCount(1, $this->saved);
        self::assertSame($vendor, $this->saved[0]->getVendor());
        self::assertMatchesRegularExpression('/^new-in-[0-9a-f]{6}$/', $this->saved[0]->getSlug());

        $data = $this->jsonBody($res)['data'];
        self::assertSame(['id', 'label', 'name', 'slug', 'display_order', 'is_active'], array_keys($data));
        self::assertSame('New In', $data['label']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array{0: User, 1: Vendor} */
    private function bindVendor(): array
    {
        $user = $this->makeUser(id: 300);
        $user->setRoles(vendor: true);

        $vendor = new Vendor('store-301', 'Store 301', 'store301@example.com');
        $vendor->approve();
        $rp = new \ReflectionProperty(Vendor::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($vendor, 301);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);

        $this->labelRepo = $this->createMock(VendorLabelRepository::class);
        $this->labelRepo->method('save')->willReturnCallback(function (VendorLabel $l): void {
            $this->forceId($l, 55);
            $this->saved[] = $l;
        });
        $labelRepo = $this->labelRepo;

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $labelRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        return [$user, $vendor];
    }

    private function forceId(VendorLabel $label, int $id): void
    {
        $rp = new \ReflectionProperty(VendorLabel::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($label, $id);
    }

    /** @param array<string, mixed> $body */
    private function send(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
