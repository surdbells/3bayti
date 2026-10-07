<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\CreateAdminVendorLabelController;
use Bayti\Api\Http\Controllers\Admin\Vendor\ListAdminVendorLabelsController;
use Bayti\Api\Http\Serializers\VendorLabelSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET + POST /v3/admin/vendors/{id}/labels, a store's labels for the admin
 * product form, keyed by the v3 vendor id in the PATH (never the admin's own
 * session, which is what /v3/vendor/labels resolves).
 */
#[CoversClass(ListAdminVendorLabelsController::class)]
#[CoversClass(CreateAdminVendorLabelController::class)]
#[CoversClass(VendorLabelSerializer::class)]
final class AdminVendorLabelsControllerTest extends HttpTestCase
{
    private const STORE = 5;
    private const OTHER_STORE = 6;

    /** @var array<int, Vendor> */
    private array $vendors = [];

    /** @var list<VendorLabel> */
    private array $allLabels = [];

    /** @var list<VendorLabel> */
    private array $savedLabels = [];

    /** @var list<int> vendor ids listActiveByVendor was asked for */
    private array $listedFor = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedLabels = [];
        $this->listedFor = [];

        $this->vendors = [
            self::STORE => $this->makeVendor(self::STORE),
            self::OTHER_STORE => $this->makeVendor(self::OTHER_STORE),
        ];

        $eid = $this->makeLabel(11, $this->vendors[self::STORE], 'Eid Collection', displayOrder: 1);
        $newIn = $this->makeLabel(12, $this->vendors[self::STORE], 'New In');
        $deleted = $this->makeLabel(13, $this->vendors[self::STORE], 'Old Stuff');
        $deleted->setActive(false);
        $foreign = $this->makeLabel(21, $this->vendors[self::OTHER_STORE], 'Other Store Label');

        $this->allLabels = [$eid, $newIn, $deleted, $foreign];
    }

    // ── GET ─────────────────────────────────────────────────────────

    #[Test]
    public function listReturnsOnlyThatStoresActiveLabelsInTheManageShape(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/vendors/' . self::STORE . '/labels');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([self::STORE], $this->listedFor, 'Labels must be read for the PATH store.');

        $data = $this->jsonBody($res)['data'];
        self::assertSame([11, 12], array_column($data, 'id'));
        self::assertSame([
            'id' => 11,
            'label' => 'Eid Collection',
            'name' => 'Eid Collection',
            'slug' => 'label-11',
            'display_order' => 1,
            'is_active' => true,
        ], $data[0]);
    }

    #[Test]
    public function listIsEmptyForAStoreWithNoLabels(): void
    {
        $this->allLabels = [];
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/vendors/' . self::STORE . '/labels');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function listReturns404ForAnUnknownStore(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/vendors/999/labels');

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->listedFor);
    }

    #[Test]
    public function listRequiresAdmin(): void
    {
        $customer = $this->makeUser(id: 200);
        $this->bindDeps($customer);

        $res = $this->send($customer, 'GET', '/v3/admin/vendors/' . self::STORE . '/labels');

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->listedFor);
    }

    #[Test]
    public function listRequiresProductsViewPermission(): void
    {
        // Back-office staff without any RBAC role reach the admin group but
        // hold no permissions, so the per-route gate must refuse them.
        $staff = $this->makeStaffWithoutPermissions();
        $this->bindDeps($staff);

        $res = $this->send($staff, 'GET', '/v3/admin/vendors/' . self::STORE . '/labels');

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.view', $this->jsonBody($res)['error']['required_permission']);
    }

    // ── POST ────────────────────────────────────────────────────────

    #[Test]
    public function createMakesTheLabelUnderThePathStore(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', [
            'label' => '  Ramadan Edit  ',
        ]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertCount(1, $this->savedLabels);
        $label = $this->savedLabels[0];
        self::assertSame(self::STORE, $label->getVendor()->getId(), 'Created under the PATH store, not the session user.');
        self::assertSame('Ramadan Edit', $label->getName());
        self::assertMatchesRegularExpression('/^ramadan-edit-[0-9a-f]{6}$/', $label->getSlug());
        self::assertTrue($label->isActive());

        $data = $this->jsonBody($res)['data'];
        self::assertSame(777, $data['id']);
        self::assertSame('Ramadan Edit', $data['label']);
        self::assertSame('Ramadan Edit', $data['name']);
        self::assertSame($label->getSlug(), $data['slug']);
        self::assertNull($data['display_order']);
        self::assertTrue($data['is_active']);
    }

    #[Test]
    public function createAcceptsNameAsAnAliasOfLabel(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', ['name' => 'Bridal']);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('Bridal', $this->savedLabels[0]->getName());
    }

    #[Test]
    public function createRejectsABlankName(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', ['label' => '   ']);

        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->savedLabels);
    }

    #[Test]
    public function createRejectsANameLongerThanTheColumn(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', [
            'label' => str_repeat('a', VendorLabel::MAX_NAME_LENGTH + 1),
        ]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('label', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame([], $this->savedLabels);
    }

    #[Test]
    public function createKeepsALongNamesSlugWithinTheColumn(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', [
            'label' => str_repeat('b', VendorLabel::MAX_NAME_LENGTH),
        ]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertLessThanOrEqual(120, strlen($this->savedLabels[0]->getSlug()));
    }

    #[Test]
    public function createReturns404ForAnUnknownStore(): void
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/vendors/999/labels', ['label' => 'Eid']);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->savedLabels);
    }

    #[Test]
    public function createRequiresAdmin(): void
    {
        $customer = $this->makeUser(id: 200);
        $this->bindDeps($customer);

        $res = $this->send($customer, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', ['label' => 'Eid']);

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->savedLabels);
    }

    #[Test]
    public function createRequiresProductsEditPermission(): void
    {
        $staff = $this->makeStaffWithoutPermissions();
        $this->bindDeps($staff);

        $res = $this->send($staff, 'POST', '/v3/admin/vendors/' . self::STORE . '/labels', ['label' => 'Eid']);

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.edit', $this->jsonBody($res)['error']['required_permission']);
        self::assertSame([], $this->savedLabels);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @param array<string, mixed> $body */
    private function send(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function bindDeps(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Vendor => $this->vendors[(int) $id] ?? null,
        );

        // Fake of the real query: active labels of the given store, by
        // display_order (NULLS LAST) then name.
        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('listActiveByVendor')->willReturnCallback(function (Vendor $v): array {
            $this->listedFor[] = (int) $v->getId();
            $rows = array_values(array_filter(
                $this->allLabels,
                static fn (VendorLabel $l): bool => $l->getVendor() === $v && $l->isActive(),
            ));
            usort($rows, static fn (VendorLabel $a, VendorLabel $b): int => [
                $a->getDisplayOrder() === null ? 1 : 0, $a->getDisplayOrder(), $a->getName(),
            ] <=> [
                $b->getDisplayOrder() === null ? 1 : 0, $b->getDisplayOrder(), $b->getName(),
            ]);
            return $rows;
        });
        $labelRepo->method('save')->willReturnCallback(function (VendorLabel $l): void {
            $rp = new \ReflectionProperty(VendorLabel::class, 'id');
            $rp->setAccessible(true);
            $rp->setValue($l, 777);
            $this->savedLabels[] = $l;
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $labelRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeAdmin(): User
    {
        $user = $this->makeUser(id: 99);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeStaffWithoutPermissions(): User
    {
        $user = $this->makeUser(id: 98);
        $user->setRoles(support: true);
        return $user;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = new Vendor("store-{$id}", "Store {$id}", "store{$id}@example.com");
        $rp = new \ReflectionProperty(Vendor::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($vendor, $id);
        return $vendor;
    }

    private function makeLabel(int $id, Vendor $vendor, string $name, ?int $displayOrder = null): VendorLabel
    {
        $label = new VendorLabel($vendor, "label-{$id}", $name);
        $label->setDisplayOrder($displayOrder);
        $rp = new \ReflectionProperty(VendorLabel::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($label, $id);
        return $label;
    }
}
