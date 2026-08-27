<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\PromoCode\CreatePromoCodeController;
use Bayti\Api\Http\Controllers\Admin\PromoCode\DeletePromoCodeController;
use Bayti\Api\Http\Controllers\Admin\PromoCode\Dto\CreatePromoCodeInput;
use Bayti\Api\Http\Controllers\Admin\PromoCode\Dto\UpdatePromoCodeInput;
use Bayti\Api\Http\Controllers\Admin\PromoCode\GetPromoCodeController;
use Bayti\Api\Http\Controllers\Admin\PromoCode\ListPromoCodesController;
use Bayti\Api\Http\Controllers\Admin\PromoCode\UpdatePromoCodeController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for the M3.2.X.8-E admin PromoCode CRUD surface.
 *
 * Endpoints:
 *   GET    /v3/admin/promo-codes
 *   GET    /v3/admin/promo-codes/{id}
 *   POST   /v3/admin/promo-codes
 *   PUT    /v3/admin/promo-codes/{id}
 *   DELETE /v3/admin/promo-codes/{id}
 *
 * Verifies:
 *   - Happy paths for each operation (200/201/204)
 *   - Validation paths (422)
 *   - Conflict on code re-use (409)
 *   - 404 on missing id
 *   - Soft vs hard delete branching based on redemption count
 *   - Audit emission for create/update/delete/view
 *
 * Test infrastructure mirrors AdminOrderControllersTest /
 * ListNotificationLogsControllerTest:
 *   - HttpTestCase drives the full Slim app
 *   - JwtService issues a real admin token (admin role required by
 *     AdminAuthMiddleware which is wired in routes.php)
 *   - EM mocked via stubEm + getRepository returnMap
 *   - AuditLog repo is an anonymous EntityRepository that captures
 *     saves into a sink array
 */
#[CoversClass(ListPromoCodesController::class)]
#[CoversClass(GetPromoCodeController::class)]
#[CoversClass(CreatePromoCodeController::class)]
#[CoversClass(UpdatePromoCodeController::class)]
#[CoversClass(DeletePromoCodeController::class)]
#[CoversClass(CreatePromoCodeInput::class)]
#[CoversClass(UpdatePromoCodeInput::class)]
#[CoversClass(PromoCodeSerializer::class)]
final class PromoCodeAdminControllersTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    // ==================================================================
    // LIST: GET /v3/admin/promo-codes
    // ==================================================================

    #[Test]
    public function listReturnsPaginatedPromoCodesWithAdminShape(): void
    {
        $admin = $this->makeAdminUser(99);
        $p1 = $this->makePromoCode('WELCOME10', id: 1);
        $p2 = $this->makePromoCode('SAVE20', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '20.00', id: 2);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->willReturn(['items' => [$p1, $p2], 'total' => 2]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertSame(2, $body['meta']['total']);
        self::assertSame(20, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertFalse($body['meta']['has_more']);

        self::assertCount(2, $body['data']);
        self::assertSame('WELCOME10', $body['data'][0]['code']);
        self::assertSame('percentage', $body['data'][0]['discount_type']);
        self::assertArrayHasKey('currently_time_valid', $body['data'][0]);
        self::assertSame('SAVE20', $body['data'][1]['code']);
        self::assertSame('fixed_amount', $body['data'][1]['discount_type']);
    }

    #[Test]
    public function listForwardsFiltersToRepository(): void
    {
        $admin = $this->makeAdminUser(99);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(function (array $filters): bool {
                // codeContains is passed through as the user typed it
                // (lowercase here); the repo normalizes to UPPER for
                // the LIKE pattern internally.
                return ($filters['isActive'] ?? null) === true
                    && ($filters['discountType'] ?? null) === 'percentage'
                    && ($filters['codeContains'] ?? null) === 'welcome'
                    && ($filters['limit'] ?? null) === 10
                    && ($filters['offset'] ?? null) === 20;
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet(
            $admin,
            '/v3/admin/promo-codes?is_active=true&discount_type=percentage&code=welcome&limit=10&offset=20',
        );
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listForwardsPlatformScopeToRepository(): void
    {
        $admin = $this->makeAdminUser(99);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(function (array $filters): bool {
                // ?scope=platform → sitewideOnly true, no vendorId.
                return ($filters['sitewideOnly'] ?? null) === true
                    && !array_key_exists('vendorId', $filters);
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes?scope=platform');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listForwardsVendorScopeToRepository(): void
    {
        $admin = $this->makeAdminUser(99);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(function (array $filters): bool {
                // ?scope=5 → vendorId 5, no sitewideOnly.
                return ($filters['vendorId'] ?? null) === 5
                    && !array_key_exists('sitewideOnly', $filters);
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes?scope=5');
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function listEmitsAuditViewedWithFilters(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findFilteredPaginated')->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes?is_active=true');
        self::assertSame(200, $response->getStatusCode());

        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_VIEWED, $this->recordedAuditLogs[0]->getAction());
        $changes = $this->recordedAuditLogs[0]->getChanges();
        self::assertSame('admin_promo_codes_list', $changes['context']);
        self::assertTrue($changes['filters']['is_active']);
    }

    #[Test]
    public function listRejectsInvalidDiscountTypeWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $this->bindEm($admin, $promoRepo);

        $response = $this->makeGet($admin, '/v3/admin/promo-codes?discount_type=lol');
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
    }

    #[Test]
    public function listClampsLimitAtMaximum(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(fn (array $f): bool => ($f['limit'] ?? null) === 100))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes?limit=9999');
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // GET: GET /v3/admin/promo-codes/{id}
    // ==================================================================

    #[Test]
    public function getReturnsPromoCodeWithRedemptionCount(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('WELCOME10', id: 7);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);

        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->expects(self::once())
            ->method('countByPromoCodeIdGross')
            ->with(7)
            ->willReturn(42);

        $this->bindEm($admin, $promoRepo, $redemptionRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes/7');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(7, $body['data']['id']);
        self::assertSame('WELCOME10', $body['data']['code']);
        self::assertSame(42, $body['data']['redemption_count']);
    }

    #[Test]
    public function getReturns404ForMissingId(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(999)->willReturn(null);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeGet($admin, '/v3/admin/promo-codes/999');
        self::assertSame(404, $response->getStatusCode());
    }

    // ==================================================================
    // CREATE: POST /v3/admin/promo-codes
    // ==================================================================

    #[Test]
    public function createPersistsPromoCodeAndReturns201(): void
    {
        $admin = $this->makeAdminUser(99);

        $persisted = [];
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturn(null);  // available
        $promoRepo->method('save')->willReturnCallback(
            function (PromoCode $p) use (&$persisted): void {
                $this->setEntityId($p, 100);
                $persisted[] = $p;
            },
        );

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePost($admin, '/v3/admin/promo-codes', [
            'code' => 'welcome10',
            'description' => 'Welcome offer',
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'min_subtotal' => '50.00',
            'usage_limit_global' => 1000,
            'is_active' => true,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('WELCOME10', $body['data']['code']);  // normalized
        self::assertSame('percentage', $body['data']['discount_type']);
        self::assertSame('10.00', $body['data']['discount_value']);
        self::assertSame('50.00', $body['data']['min_subtotal']);
        self::assertSame(1000, $body['data']['usage_limit_global']);
        self::assertSame('AED', $body['data']['currency']);

        self::assertCount(1, $persisted);
        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_CREATED, $this->recordedAuditLogs[0]->getAction());
    }

    #[Test]
    public function createReturns409WhenCodeAlreadyTaken(): void
    {
        $admin = $this->makeAdminUser(99);
        $existing = $this->makePromoCode('WELCOME10', id: 1);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturn($existing);
        $promoRepo->expects(self::never())->method('save');

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePost($admin, '/v3/admin/promo-codes', [
            'code' => 'welcome10',
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
        ]);

        self::assertSame(409, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('promo_code_taken', $body['error']['code']);
        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function createReturns422ForMissingRequiredFields(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $this->bindEm($admin, $promoRepo);

        // Missing code + discount_type + discount_value.
        $response = $this->makePost($admin, '/v3/admin/promo-codes', []);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
    }

    #[Test]
    public function createReturns422ForInvalidDateRange(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturn(null);
        $promoRepo->expects(self::never())->method('save');

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePost($admin, '/v3/admin/promo-codes', [
            'code' => 'WINDOW',
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'valid_from' => '2030-01-01T00:00:00Z',
            'valid_until' => '2020-01-01T00:00:00Z',  // before valid_from
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
        self::assertArrayHasKey('valid_until', $body['error']['details']['fields']);
    }

    #[Test]
    public function createReturns422ForPercentageAboveHundred(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturn(null);
        $promoRepo->expects(self::never())->method('save');

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePost($admin, '/v3/admin/promo-codes', [
            'code' => 'OVER',
            'discount_type' => 'percentage',
            'discount_value' => '150.00',  // exceeds 100, entity rejects
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    // ==================================================================
    // UPDATE: PUT /v3/admin/promo-codes/{id}
    // ==================================================================

    #[Test]
    public function updateAppliesPartialChangesAndEmitsAudit(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('WELCOME10', id: 7);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        $promoRepo->method('findByNormalizedCode')->willReturn(null);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePut($admin, '/v3/admin/promo-codes/7', [
            'description' => 'Updated description',
            'usage_limit_global' => 500,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('Updated description', $body['data']['description']);
        self::assertSame(500, $body['data']['usage_limit_global']);
        // Unchanged fields still present
        self::assertSame('WELCOME10', $body['data']['code']);
        self::assertSame('10.00', $body['data']['discount_value']);

        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_UPDATED, $this->recordedAuditLogs[0]->getAction());
    }

    #[Test]
    public function updateReturns409WhenChangingCodeToTaken(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('OLD', id: 7);
        $occupier = $this->makePromoCode('TAKEN', id: 8);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        $promoRepo->method('findByNormalizedCode')->willReturnCallback(
            fn (string $code): ?PromoCode => PromoCode::normalizeCode($code) === 'TAKEN' ? $occupier : null,
        );

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePut($admin, '/v3/admin/promo-codes/7', [
            'code' => 'taken',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('promo_code_taken', $this->jsonBody($response)['error']['code']);
    }

    #[Test]
    public function updateAllowsCodeChangeToSameValueIdempotently(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('SAME', id: 7);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        // findByNormalizedCode should NOT be called because new code == current.

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePut($admin, '/v3/admin/promo-codes/7', [
            'code' => 'SAME',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function updateReturns404ForMissingId(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(999)->willReturn(null);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makePut($admin, '/v3/admin/promo-codes/999', ['description' => 'x']);
        self::assertSame(404, $response->getStatusCode());
    }

    // ==================================================================
    // DELETE: DELETE /v3/admin/promo-codes/{id}
    // ==================================================================

    #[Test]
    public function deleteSoftDeletesWhenRedemptionsExist(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('USED', id: 7);
        self::assertTrue($promo->isActive());

        $removed = false;
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        $promoRepo->expects(self::never())->method('remove');

        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->method('countByPromoCodeIdGross')->with(7)->willReturn(5);

        $this->bindEm($admin, $promoRepo, $redemptionRepo);
        $response = $this->makeDelete($admin, '/v3/admin/promo-codes/7');

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($promo->isActive(), 'soft delete should clear is_active');

        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_DELETED, $this->recordedAuditLogs[0]->getAction());
    }

    #[Test]
    public function deleteHardDeletesWhenZeroRedemptions(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('TYPO', id: 7);

        $removedRows = [];
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        $promoRepo->expects(self::once())
            ->method('remove')
            ->willReturnCallback(function (PromoCode $p) use (&$removedRows): void {
                $removedRows[] = $p;
            });

        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->method('countByPromoCodeIdGross')->with(7)->willReturn(0);

        $this->bindEm($admin, $promoRepo, $redemptionRepo);
        $response = $this->makeDelete($admin, '/v3/admin/promo-codes/7');

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $removedRows);
        self::assertTrue($promo->isActive(), 'hard-delete path does not flip is_active');
    }

    #[Test]
    public function deleteReturns404ForMissingId(): void
    {
        $admin = $this->makeAdminUser(99);
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(999)->willReturn(null);

        $this->bindEm($admin, $promoRepo);
        $response = $this->makeDelete($admin, '/v3/admin/promo-codes/999');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteIdempotentForAlreadyInactiveCodeWithRedemptions(): void
    {
        $admin = $this->makeAdminUser(99);
        $promo = $this->makePromoCode('OLD', id: 7);
        $promo->setActive(false);  // already inactive

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('find')->with(7)->willReturn($promo);
        $promoRepo->expects(self::never())->method('remove');

        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->method('countByPromoCodeIdGross')->willReturn(3);

        $this->bindEm($admin, $promoRepo, $redemptionRepo);
        $response = $this->makeDelete($admin, '/v3/admin/promo-codes/7');

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($promo->isActive());
        // Audit still emits because we record the delete intent regardless.
        self::assertCount(1, $this->recordedAuditLogs);
    }

    // ==================================================================
    // Auth
    // ==================================================================

    #[Test]
    public function requiresAdminRole(): void
    {
        $regularUser = $this->makeUser(id: 7);  // no admin role
        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $this->bindEm($regularUser, $promoRepo);

        $response = $this->makeGet($regularUser, '/v3/admin/promo-codes');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        // No bearer token at all.
        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/promo-codes'));
        self::assertSame(401, $response->getStatusCode());
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makePromoCode(
        string $code,
        string $type = PromoCode::DISCOUNT_TYPE_PERCENTAGE,
        string $value = '10.00',
        int $id = 1,
    ): PromoCode {
        $promo = new PromoCode($code, $type, $value);
        $this->setEntityId($promo, $id);
        return $promo;
    }

    private function bindEm(
        User $user,
        PromoCodeRepository $promoRepo,
        ?PromoRedemptionRepository $redemptionRepo = null,
    ): EntityManagerInterface {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        // AuditLog repo: anonymous EntityRepository capturing saves.
        $sink = &$this->recordedAuditLogs;
        $auditRepo = new class($sink) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int, AuditLog> $sink */
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string { return AuditLog::class; }
        };

        $redemptionRepo ??= $this->createMock(PromoRedemptionRepository::class);

        $em = $this->stubEm(function ($em) use ($userRepo, $promoRepo, $redemptionRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [PromoCode::class, $promoRepo],
                [PromoRedemption::class, $redemptionRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('flush');
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(
            \Bayti\Api\Domain\Audit\AuditEmitter::class,
            new \Bayti\Api\Domain\Audit\AuditEmitter($em, new \Psr\Log\NullLogger()),
        );
        return $em;
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, [], $this->headers($user)));
    }

    /** @param array<string, mixed> $body */
    private function makePost(User $user, string $uri, array $body): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', $uri, $body, $this->headers($user)));
    }

    /** @param array<string, mixed> $body */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        return $this->handle($this->jsonRequest('PUT', $uri, $body, $this->headers($user)));
    }

    private function makeDelete(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('DELETE', $uri, [], $this->headers($user)));
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return ['Authorization' => 'Bearer ' . $pair->accessToken];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
