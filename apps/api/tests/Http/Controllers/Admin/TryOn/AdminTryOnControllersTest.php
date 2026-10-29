<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\TryOn;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Ai\TryOn\VirtualTryOnResult;
use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Product\GetAdminProductController;
use Bayti\Api\Http\Controllers\Admin\TryOn\BulkSetAdminTryOnProductsController;
use Bayti\Api\Http\Controllers\Admin\TryOn\GetAdminTryOnStatusController;
use Bayti\Api\Http\Controllers\Admin\TryOn\ListAdminTryOnProductsController;
use Bayti\Api\Http\Controllers\Admin\TryOn\SetAdminProductTryOnController;
use Bayti\Api\Http\Serializers\AdminTryOnProductSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin virtual try-on enablement API:
 *   GET  /v3/admin/try-on/status          (products.view)
 *   GET  /v3/admin/try-on/products        (products.view)
 *   PUT  /v3/admin/products/{id}/try-on   (products.edit)
 *   POST /v3/admin/try-on/products/bulk   (products.edit)
 * plus the raw try-on fields on GET /v3/admin/products/{id}.
 *
 * Product ids are v3 ids. Eligibility is the shared TryOnGarmentEligibility
 * rule the try-on worker applies.
 */
#[CoversClass(GetAdminTryOnStatusController::class)]
#[CoversClass(ListAdminTryOnProductsController::class)]
#[CoversClass(SetAdminProductTryOnController::class)]
#[CoversClass(BulkSetAdminTryOnProductsController::class)]
#[CoversClass(AdminTryOnProductSerializer::class)]
#[CoversClass(GetAdminProductController::class)]
final class AdminTryOnControllersTest extends HttpTestCase
{
    private const BASE = 'https://cdn.test/uploads';
    private const ENV_KEYS = ['UPLOADS_PUBLIC_URL', 'TRYON_ENABLED', 'AI_ENABLED', 'OPENAI_API_KEY'];

    /** @var array<string, string|null> */
    private array $envBackup = [];

    private Vendor $vendor;

    /** @var array<int, Product> */
    private array $products = [];

    private int $saveCalls = 0;
    private int $flushCalls = 0;

    /** @var array<string, mixed>|null */
    private ?array $listFilters = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
        }
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
        $_ENV['TRYON_ENABLED'] = 'false';
        $_ENV['AI_ENABLED'] = 'false';

        $this->vendor = new Vendor('rose-atelier', 'Rose Atelier', 'rose@example.test');
        $this->setPrivate($this->vendor, 'id', 5);

        $this->products = [];
        $this->saveCalls = 0;
        $this->flushCalls = 0;
        $this->listFilters = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    // ── GET /v3/admin/try-on/status ─────────────────────────────────

    #[Test]
    public function statusReportsAProviderThatIsOff(): void
    {
        $admin = $this->admin();
        $this->bindDeps($admin, enabledCount: 3);

        $res = $this->send($admin, 'GET', '/v3/admin/try-on/status');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([
            'available' => false,
            'tryon_flag' => false,
            'ai_enabled' => false,
            'enabled_products' => 3,
            'eligible_formats' => ['jpeg', 'png', 'webp'],
        ], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function statusReportsALiveProviderAndTheRawEnvFlags(): void
    {
        $_ENV['TRYON_ENABLED'] = 'true';
        $_ENV['AI_ENABLED'] = 'true';
        $admin = $this->admin();
        $this->bindDeps($admin, enabledCount: 0);
        $this->bind(VirtualTryOnProviderInterface::class, $this->provider(true));

        $data = $this->jsonBody($this->send($admin, 'GET', '/v3/admin/try-on/status'))['data'];

        self::assertTrue($data['available']);
        self::assertTrue($data['tryon_flag']);
        self::assertTrue($data['ai_enabled']);
        self::assertSame(0, $data['enabled_products']);
    }

    #[Test]
    public function statusDegradesAMisconfiguredProviderToUnavailableInsteadOf500(): void
    {
        // The real provider factory throws when TRYON_ENABLED + AI_ENABLED are
        // set without an OpenAI key. The status (and every product page) must
        // keep working and report the feature as unavailable.
        $_ENV['TRYON_ENABLED'] = 'true';
        $_ENV['AI_ENABLED'] = 'true';
        $_ENV['OPENAI_API_KEY'] = '';
        $admin = $this->admin();
        $this->bindDeps($admin);

        // Premise: the provider entry itself really is broken in this config.
        try {
            $this->app->getContainer()?->get(VirtualTryOnProviderInterface::class);
            self::fail('Expected the misconfigured try-on provider factory to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }

        $res = $this->send($admin, 'GET', '/v3/admin/try-on/status');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertFalse($data['available']);
        self::assertTrue($data['tryon_flag']);
        self::assertTrue($data['ai_enabled']);
    }

    #[Test]
    public function statusRequiresProductsView(): void
    {
        $staff = $this->staff([]);
        $this->bindDeps($staff);

        $res = $this->send($staff, 'GET', '/v3/admin/try-on/status');

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.view', $this->jsonBody($res)['error']['required_permission']);
    }

    // ── GET /v3/admin/try-on/products ───────────────────────────────

    #[Test]
    public function listReturnsTheRowShapeWithEligibilityAndAdminPaginationMeta(): void
    {
        $admin = $this->admin();
        $ok = $this->product(101, true, self::BASE . '/products/rose/a.jpg');
        $gif = $this->product(102, false, self::BASE . '/products/rose/b.gif');
        $external = $this->product(103, false, 'https://images.example.com/c.png');
        $none = $this->product(104, false, null);
        $this->bindDeps($admin, listResult: ['items' => [$ok, $gif, $external, $none], 'total' => 45]);

        $res = $this->send($admin, 'GET', '/v3/admin/try-on/products?page=2&limit=20');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame(['total' => 45, 'limit' => 20, 'offset' => 20, 'has_more' => true], $body['meta']);
        self::assertCount(4, $body['data']);
        self::assertSame([
            'id' => 101,
            'slug' => 'product-101',
            'name' => 'Product 101',
            'status' => Product::STATUS_ACTIVE,
            'vendor' => ['id' => 5, 'name' => 'Rose Atelier', 'slug' => 'rose-atelier'],
            'primary_image_url' => self::BASE . '/products/rose/a.jpg',
            'try_on_enabled' => true,
            'eligibility' => ['eligible' => true, 'reason' => null],
        ], $body['data'][0]);
        self::assertSame(['eligible' => false, 'reason' => 'unsupported_format'], $body['data'][1]['eligibility']);
        self::assertSame(['eligible' => false, 'reason' => 'external_image'], $body['data'][2]['eligibility']);
        self::assertSame(['eligible' => false, 'reason' => 'no_image'], $body['data'][3]['eligibility']);
        self::assertNull($body['data'][3]['primary_image_url']);
        self::assertArrayNotHasKey('legacy_id', $body['data'][0]['vendor']);
        self::assertArrayNotHasKey('legacy_product_id', $body['data'][0]);

        self::assertSame(['q' => null, 'enabled' => null, 'limit' => 20, 'offset' => 20], $this->listFilters);
    }

    #[Test]
    public function listPassesSearchEnabledFilterAndCapsTheLimit(): void
    {
        $admin = $this->admin();
        $this->bindDeps($admin, listResult: ['items' => [], 'total' => 0]);

        $res = $this->send($admin, 'GET', '/v3/admin/try-on/products?q=%20abaya%20&enabled=true&limit=500');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['q' => 'abaya', 'enabled' => true, 'limit' => 100, 'offset' => 0], $this->listFilters);
        self::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'has_more' => false], $this->jsonBody($res)['meta']);

        $this->send($admin, 'GET', '/v3/admin/try-on/products?enabled=false');
        self::assertSame(['q' => null, 'enabled' => false, 'limit' => 20, 'offset' => 0], $this->listFilters);

        $this->send($admin, 'GET', '/v3/admin/try-on/products?enabled=maybe&offset=40');
        self::assertSame(['q' => null, 'enabled' => null, 'limit' => 20, 'offset' => 40], $this->listFilters);
    }

    #[Test]
    public function listRequiresProductsView(): void
    {
        $staff = $this->staff([]);
        $this->bindDeps($staff);

        $res = $this->send($staff, 'GET', '/v3/admin/try-on/products');

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.view', $this->jsonBody($res)['error']['required_permission']);
    }

    // ── PUT /v3/admin/products/{id}/try-on ──────────────────────────

    #[Test]
    public function enablingAnEligibleProductSetsTheFlagAndReturnsTheRow(): void
    {
        $admin = $this->admin();
        $product = $this->product(101, false, self::BASE . '/products/rose/a.png');
        $this->bindDeps($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertTrue($product->isTryOnEnabled());
        self::assertSame(1, $this->saveCalls);
        $data = $this->jsonBody($res)['data'];
        self::assertSame(101, $data['id']);
        self::assertTrue($data['try_on_enabled']);
        self::assertSame(['eligible' => true, 'reason' => null], $data['eligibility']);
    }

    #[Test]
    public function enablingWorksWhileTheProviderIsOff(): void
    {
        // Admins prepare products before go-live: the toggle does not depend on
        // TRYON_ENABLED (the public CTA does).
        $admin = $this->admin();
        $product = $this->product(101, false, self::BASE . '/products/rose/a.webp');
        $this->bindDeps($admin);
        $this->bind(VirtualTryOnProviderInterface::class, $this->provider(false));

        $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertTrue($product->isTryOnEnabled());
    }

    #[Test]
    public function enablingAnIneligibleProductIs422WithTheReason(): void
    {
        $cases = [
            TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT => self::BASE . '/products/rose/a.gif',
            TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE => 'https://images.example.com/a.jpg',
            TryOnGarmentEligibility::REASON_NO_IMAGE => null,
        ];

        $admin = $this->admin();
        $this->bindDeps($admin);

        foreach ($cases as $reason => $url) {
            // The repo mock reads $this->products live, so swap the product in.
            $product = $this->product(101, false, $url);

            $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

            self::assertSame(422, $res->getStatusCode(), $reason . ': ' . $res->getBody());
            $error = $this->jsonBody($res)['error'];
            self::assertSame('TRYON_IMAGE_INELIGIBLE', $error['code'], $reason);
            self::assertSame($reason, $error['details']['reason'], $reason);
            self::assertSame(TryOnGarmentEligibility::reasonMessage($reason), $error['message'], $reason);
            self::assertFalse($product->isTryOnEnabled(), $reason);
            self::assertSame(0, $this->saveCalls, $reason);
        }
    }

    #[Test]
    public function disablingIsAlwaysAllowedEvenForAnIneligibleImage(): void
    {
        $admin = $this->admin();
        $product = $this->product(101, true, self::BASE . '/products/rose/a.gif');
        $this->bindDeps($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => false]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertFalse($product->isTryOnEnabled());
        self::assertSame(1, $this->saveCalls);
        self::assertFalse($this->jsonBody($res)['data']['try_on_enabled']);
    }

    #[Test]
    public function settingTheCurrentValueIsANoOpWrite(): void
    {
        $admin = $this->admin();
        $this->product(101, true, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->saveCalls, 'no write, so no audit row');
    }

    #[Test]
    public function unknownProductIs404(): void
    {
        $admin = $this->admin();
        $this->bindDeps($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/999/try-on', ['enabled' => false]);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function aSoftDeletedProductIs404AndIsNotWritten(): void
    {
        $admin = $this->admin();
        $product = $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $product->setStatus(Product::STATUS_SOFT_DELETED);
        $this->bindDeps($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        self::assertFalse($product->isTryOnEnabled());
        self::assertSame(0, $this->saveCalls);
    }

    #[Test]
    public function aNonBooleanEnabledIs422(): void
    {
        $admin = $this->admin();
        $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($admin);

        foreach ([[], ['enabled' => 'true'], ['enabled' => 1]] as $body) {
            $res = $this->send($admin, 'PUT', '/v3/admin/products/101/try-on', $body);
            self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
            self::assertArrayHasKey('enabled', $this->jsonBody($res)['error']['details']['fields']);
        }
    }

    #[Test]
    public function toggleRequiresProductsEdit(): void
    {
        // A viewer can read the try-on screens but not change them.
        $viewer = $this->staff(['products.view']);
        $product = $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($viewer);

        $res = $this->send($viewer, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true]);

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.edit', $this->jsonBody($res)['error']['required_permission']);
        self::assertFalse($product->isTryOnEnabled());

        self::assertSame(200, $this->send($viewer, 'GET', '/v3/admin/try-on/status')->getStatusCode());
    }

    #[Test]
    public function aCustomerCannotReachTheTryOnAdmin(): void
    {
        $customer = $this->makeUser(id: 200);
        $this->bindDeps($customer);

        self::assertSame(403, $this->send($customer, 'GET', '/v3/admin/try-on/products')->getStatusCode());
        self::assertSame(403, $this->send($customer, 'PUT', '/v3/admin/products/101/try-on', ['enabled' => true])->getStatusCode());
    }

    // ── POST /v3/admin/try-on/products/bulk ─────────────────────────

    #[Test]
    public function bulkEnableUpdatesEligibleProductsAndSkipsTheRest(): void
    {
        $admin = $this->admin();
        $a = $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $b = $this->product(102, true, self::BASE . '/products/rose/b.png');
        $gif = $this->product(103, false, self::BASE . '/products/rose/c.gif');
        $external = $this->product(104, false, 'https://images.example.com/d.jpg');
        $none = $this->product(105, false, null);
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/try-on/products/bulk', [
            'product_ids' => [101, 999, 102, 103, '101', 104, 105],
            'enabled' => true,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([
            'updated' => [101, 102],
            'skipped' => [
                ['id' => 999, 'reason' => 'not_found'],
                ['id' => 103, 'reason' => 'unsupported_format'],
                ['id' => 104, 'reason' => 'external_image'],
                ['id' => 105, 'reason' => 'no_image'],
            ],
        ], $this->jsonBody($res)['data']);
        self::assertTrue($a->isTryOnEnabled());
        self::assertTrue($b->isTryOnEnabled());
        self::assertFalse($gif->isTryOnEnabled());
        self::assertFalse($external->isTryOnEnabled());
        self::assertFalse($none->isTryOnEnabled());
        self::assertSame(1, $this->flushCalls, 'one flush for the whole batch');
    }

    #[Test]
    public function bulkDisableNeverSkipsForImageReasons(): void
    {
        $admin = $this->admin();
        $gif = $this->product(103, true, self::BASE . '/products/rose/c.gif');
        $none = $this->product(105, true, null);
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/try-on/products/bulk', [
            'product_ids' => [103, 105, 404],
            'enabled' => false,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([
            'updated' => [103, 105],
            'skipped' => [['id' => 404, 'reason' => 'not_found']],
        ], $this->jsonBody($res)['data']);
        self::assertFalse($gif->isTryOnEnabled());
        self::assertFalse($none->isTryOnEnabled());
        self::assertSame(1, $this->flushCalls);
    }

    #[Test]
    public function bulkWithNothingToChangeDoesNotFlush(): void
    {
        $admin = $this->admin();
        $this->product(101, true, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/try-on/products/bulk', [
            'product_ids' => [101],
            'enabled' => true,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['updated' => [101], 'skipped' => []], $this->jsonBody($res)['data']);
        self::assertSame(0, $this->flushCalls);
    }

    #[Test]
    public function bulkValidatesIdsAndEnabled(): void
    {
        $admin = $this->admin();
        $this->bindDeps($admin);

        $tooMany = range(1, BulkSetAdminTryOnProductsController::MAX_IDS + 1);
        $cases = [
            'missing ids' => [['enabled' => true], 'product_ids'],
            'empty ids' => [['product_ids' => [], 'enabled' => true], 'product_ids'],
            'bad id' => [['product_ids' => [1, 'x'], 'enabled' => true], 'product_ids'],
            'too many' => [['product_ids' => $tooMany, 'enabled' => true], 'product_ids'],
            'missing enabled' => [['product_ids' => [1]], 'enabled'],
            'string enabled' => [['product_ids' => [1], 'enabled' => 'yes'], 'enabled'],
        ];

        foreach ($cases as $case => [$body, $field]) {
            $res = $this->send($admin, 'POST', '/v3/admin/try-on/products/bulk', $body);
            self::assertSame(422, $res->getStatusCode(), $case . ': ' . $res->getBody());
            self::assertArrayHasKey($field, $this->jsonBody($res)['error']['details']['fields'], $case);
        }
        self::assertSame(0, $this->flushCalls);
    }

    #[Test]
    public function bulkAcceptsExactlyTheMaximumAfterDeduplication(): void
    {
        $admin = $this->admin();
        $this->bindDeps($admin);

        // 200 distinct ids sent twice over = 200 after de-duplication: allowed.
        $ids = range(1, BulkSetAdminTryOnProductsController::MAX_IDS);
        $res = $this->send($admin, 'POST', '/v3/admin/try-on/products/bulk', [
            'product_ids' => array_merge($ids, $ids),
            'enabled' => false,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertCount(BulkSetAdminTryOnProductsController::MAX_IDS, $this->jsonBody($res)['data']['skipped']);
    }

    #[Test]
    public function bulkRequiresProductsEdit(): void
    {
        $viewer = $this->staff(['products.view']);
        $product = $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($viewer);

        $res = $this->send($viewer, 'POST', '/v3/admin/try-on/products/bulk', [
            'product_ids' => [101],
            'enabled' => true,
        ]);

        self::assertSame(403, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('products.edit', $this->jsonBody($res)['error']['required_permission']);
        self::assertFalse($product->isTryOnEnabled());
    }

    // ── GET /v3/admin/products/{id} exposes the RAW flag ────────────

    #[Test]
    public function adminProductDetailExposesTheRawFlagAvailabilityAndEligibility(): void
    {
        $admin = $this->admin();
        $this->product(101, true, self::BASE . '/products/rose/a.gif');
        $this->bindDeps($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/products/101');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertTrue($data['try_on_enabled'], 'raw stored flag, not gated by provider/eligibility');
        self::assertFalse($data['try_on_available']);
        self::assertSame(['eligible' => false, 'reason' => 'unsupported_format'], $data['try_on_eligibility']);
    }

    #[Test]
    public function adminProductDetailReportsALiveProvider(): void
    {
        $admin = $this->admin();
        $this->product(101, false, self::BASE . '/products/rose/a.jpg');
        $this->bindDeps($admin);
        $this->bind(VirtualTryOnProviderInterface::class, $this->provider(true));

        $data = $this->jsonBody($this->send($admin, 'GET', '/v3/admin/products/101'))['data'];

        self::assertFalse($data['try_on_enabled']);
        self::assertTrue($data['try_on_available']);
        self::assertSame(['eligible' => true, 'reason' => null], $data['try_on_eligibility']);
    }

    // ── Public PDP gating through the real container wiring ────────

    #[Test]
    public function containerWiredProductSerializerGatesThePublicFlagOnTheLiveProvider(): void
    {
        // The public flag also requires an orderable product (approved store).
        $this->vendor->approve();
        $eligible = $this->product(101, true, self::BASE . '/products/rose/a.jpg');
        $gif = $this->product(102, true, self::BASE . '/products/rose/b.gif');

        // Provider off (test env default: TRYON_ENABLED=false → Null provider).
        $off = $this->app->getContainer()?->get(ProductSerializer::class);
        self::assertInstanceOf(ProductSerializer::class, $off);
        self::assertFalse($off->detailShape($eligible)['try_on_enabled']);

        // Provider live: a fresh app so the serializer singleton is rebuilt.
        $this->app = \Bayti\Api\Bootstrap::createApp();
        $this->bind(VirtualTryOnProviderInterface::class, $this->provider(true));
        $on = $this->app->getContainer()?->get(ProductSerializer::class);
        self::assertInstanceOf(ProductSerializer::class, $on);
        self::assertTrue($on->detailShape($eligible)['try_on_enabled']);
        self::assertFalse($on->detailShape($gif)['try_on_enabled']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array{items: list<Product>, total: int}|null $listResult
     */
    private function bindDeps(User $user, int $enabledCount = 0, ?array $listResult = null): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Product => $this->products[(int) $id] ?? null,
        );
        $productRepo->method('findByIds')->willReturnCallback(
            fn (array $ids): array => array_values(array_filter(
                $this->products,
                static fn (Product $p): bool => in_array($p->getId(), $ids, true),
            )),
        );
        $productRepo->method('findForTryOnAdmin')->willReturnCallback(
            function (array $filters) use ($listResult): array {
                $this->listFilters = $filters;
                return $listResult ?? ['items' => [], 'total' => 0];
            },
        );
        $productRepo->method('countTryOnEnabled')->willReturn($enabledCount);
        $productRepo->method('save')->willReturnCallback(function (): void {
            $this->saveCalls++;
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
            ]);
            $em->method('flush')->willReturnCallback(function (): void {
                $this->flushCalls++;
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    /** @param array<string, mixed> $body */
    private function send(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $request = $this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]);
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }

        return $this->handle($request);
    }

    private function admin(): User
    {
        $user = $this->makeUser(id: 99);
        $user->setRoles(admin: true);
        return $user;
    }

    /** Back-office staff holding exactly the given permissions. */
    private function staff(array $permissionKeys): User
    {
        $user = $this->makeUser(id: 98);
        $user->setRoles(support: true);
        if ($permissionKeys !== []) {
            $role = new Role('catalog-viewer', 'Catalog viewer');
            foreach ($permissionKeys as $key) {
                $role->addPermission(new Permission($key, explode('.', $key)[0], $key));
            }
            $user->addRole($role);
        }
        return $user;
    }

    private function product(int $id, bool $tryOnEnabled, ?string $primaryImageUrl): Product
    {
        $product = new Product($this->vendor, "product-{$id}", "Product {$id}");
        $this->setPrivate($product, 'id', $id);
        $product->setPrice('100.00');
        $product->setStatus(Product::STATUS_ACTIVE);
        $product->setPrimaryImageUrl($primaryImageUrl);
        $product->setTryOnEnabled($tryOnEnabled);
        $this->products[$id] = $product;
        return $product;
    }

    private function provider(bool $enabled): VirtualTryOnProviderInterface
    {
        return new class ($enabled) implements VirtualTryOnProviderInterface {
            public function __construct(private readonly bool $enabled)
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function generate(
                string $personBytes,
                string $personMime,
                string $garmentBytes,
                string $garmentMime,
                string $garmentPrompt,
            ): VirtualTryOnResult {
                return VirtualTryOnResult::empty();
            }
        };
    }

    private function setPrivate(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
