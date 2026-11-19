<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardRepository;
use Bayti\Api\Domain\Cashback\CashbackProgramSettings;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleRepository;
use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Cashback\CancelCashbackAwardController;
use Bayti\Api\Http\Controllers\Admin\Cashback\CreateCashbackRuleController;
use Bayti\Api\Http\Controllers\Admin\Cashback\DeleteCashbackRuleController;
use Bayti\Api\Http\Controllers\Admin\Cashback\GetCashbackSettingsController;
use Bayti\Api\Http\Controllers\Admin\Cashback\ListCashbackAwardsController;
use Bayti\Api\Http\Controllers\Admin\Cashback\ListCashbackRulesController;
use Bayti\Api\Http\Controllers\Admin\Cashback\ReleaseCashbackAwardController;
use Bayti\Api\Http\Controllers\Admin\Cashback\ReverseCashbackAwardController;
use Bayti\Api\Http\Controllers\Admin\Cashback\SetCashbackRuleActiveController;
use Bayti\Api\Http\Controllers\Admin\Cashback\UpdateCashbackRuleController;
use Bayti\Api\Http\Controllers\Admin\Cashback\UpdateCashbackSettingsController;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(GetCashbackSettingsController::class)]
#[CoversClass(UpdateCashbackSettingsController::class)]
#[CoversClass(ListCashbackRulesController::class)]
#[CoversClass(CreateCashbackRuleController::class)]
#[CoversClass(UpdateCashbackRuleController::class)]
#[CoversClass(SetCashbackRuleActiveController::class)]
#[CoversClass(DeleteCashbackRuleController::class)]
#[CoversClass(ListCashbackAwardsController::class)]
#[CoversClass(ReleaseCashbackAwardController::class)]
#[CoversClass(CancelCashbackAwardController::class)]
#[CoversClass(ReverseCashbackAwardController::class)]
#[CoversClass(CashbackSerializer::class)]
final class CashbackAdminControllersTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $audits = [];
    private CashbackRuleRepository&MockObject $rules;
    private CashbackAwardRepository&MockObject $awards;
    private CashbackSettingsStore&MockObject $settings;
    private ?CashbackProgramSettings $saved = null;
    /** @var list<object> */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->audits = [];
        $this->removed = [];
        $this->saved = null;
        $this->rules = $this->createMock(CashbackRuleRepository::class);
        $this->awards = $this->createMock(CashbackAwardRepository::class);
        $this->settings = $this->createMock(CashbackSettingsStore::class);
        $this->settings->method('load')->willReturn(CashbackProgramSettings::default());
        $this->settings->method('save')->willReturnCallback(function (CashbackProgramSettings $s): void {
            $this->saved = $s;
        });
    }

    // ── permissions ─────────────────────────────────────────────────

    #[Test]
    public function aStaffUserWithViewOnlyCanReadButNotWrite(): void
    {
        $viewer = $this->staff(30, ['cashback.view']);
        $this->rules->method('findFilteredPaginated')->willReturn(['items' => [], 'total' => 0]);
        $this->bindEnv($viewer);

        self::assertSame(200, $this->call($viewer, 'GET', '/v3/admin/cashback/rules')->getStatusCode());
        self::assertSame(200, $this->call($viewer, 'GET', '/v3/admin/cashback/settings')->getStatusCode());

        $denied = $this->call($viewer, 'POST', '/v3/admin/cashback/rules', ['name' => 'X', 'reward_type' => 'percentage', 'reward_value' => '5']);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('cashback.create', $this->jsonBody($denied)['error']['required_permission']);

        $denied2 = $this->call($viewer, 'POST', '/v3/admin/cashback/awards/1/release', ['reason' => 'x']);
        self::assertSame(403, $denied2->getStatusCode());
        self::assertSame('cashback.manage', $this->jsonBody($denied2)['error']['required_permission']);
    }

    #[Test]
    public function aStaffUserWithoutCashbackPermissionsIsRefused(): void
    {
        $other = $this->staff(31, ['coupons.view']);
        $this->bindEnv($other);
        self::assertSame(403, $this->call($other, 'GET', '/v3/admin/cashback/awards')->getStatusCode());
        self::assertSame(403, $this->call($other, 'GET', '/v3/admin/cashback/summary')->getStatusCode());
    }

    #[Test]
    public function customersAndAnonymousCallersAreRefused(): void
    {
        $customer = $this->makeUser(id: 40);
        $this->bindEnv($customer);
        self::assertSame(403, $this->call($customer, 'GET', '/v3/admin/cashback/rules')->getStatusCode());
        self::assertSame(401, $this->handle($this->jsonRequest('GET', '/v3/admin/cashback/rules'))->getStatusCode());
    }

    // ── settings ────────────────────────────────────────────────────

    #[Test]
    public function settingsCanBeReadAndUpdatedAndAreAudited(): void
    {
        $admin = $this->admin();
        $this->bindEnv($admin);

        $get = $this->jsonBody($this->call($admin, 'GET', '/v3/admin/cashback/settings'));
        self::assertFalse($get['data']['enabled']);
        self::assertSame('1.00', $get['data']['min_award']);

        $put = $this->call($admin, 'PUT', '/v3/admin/cashback/settings', ['enabled' => true, 'max_award_per_order' => '250', 'default_hold_days' => 10]);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $body = $this->jsonBody($put);
        self::assertTrue($body['data']['enabled']);
        self::assertSame('250.00', $body['data']['max_award_per_order']);
        self::assertNotNull($this->saved);
        self::assertSame(10, $this->saved->defaultHoldDays);
        self::assertCount(1, $this->audits);
        self::assertSame('Setting', $this->audits[0]->getSubjectType());

        $bad = $this->call($admin, 'PUT', '/v3/admin/cashback/settings', ['enabled' => 'yes']);
        self::assertSame(422, $bad->getStatusCode());
    }

    // ── rules ───────────────────────────────────────────────────────

    #[Test]
    public function listReturnsPaginatedRulesWithBudgetStats(): void
    {
        $admin = $this->admin();
        $rule = $this->rule(3, 'Ramadan 5%');
        $rule->setBudgetTotal('1000.00');
        $this->rules->expects(self::once())->method('findFilteredPaginated')
            ->with(self::callback(static fn (array $f): bool => $f['status'] === 'active' && $f['limit'] === 10 && $f['search'] === 'ram'))
            ->willReturn(['items' => [$rule], 'total' => 1]);
        $this->rules->method('spentByRuleIds')->willReturn([3 => '250.00']);
        $this->rules->method('awardCountsByRuleIds')->willReturn([3 => 12]);
        $this->bindEnv($admin);

        $res = $this->call($admin, 'GET', '/v3/admin/cashback/rules?status=active&limit=10&search=ram');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame(1, $body['meta']['total']);
        self::assertSame('Ramadan 5%', $body['data'][0]['name']);
        self::assertSame('250.00', $body['data'][0]['budget_spent']);
        self::assertSame('750.00', $body['data'][0]['budget_remaining']);
        self::assertSame(12, $body['data'][0]['awards_count']);
        self::assertSame('active', $body['data'][0]['status']);

        self::assertSame(422, $this->call($admin, 'GET', '/v3/admin/cashback/rules?status=bogus')->getStatusCode());
    }

    #[Test]
    public function createValidatesAndPersistsAStoreScopedRule(): void
    {
        $admin = $this->admin();
        $vendor = new Vendor('chic-store', 'Chic Store', 'chic@example.test');
        $this->setId($vendor, 10);
        $this->rules->expects(self::once())->method('save')->willReturnCallback(function (CashbackRule $r): void {
            $this->setId($r, 77);
        });
        $this->bindEnv($admin, static fn (string $class, mixed $id): ?object => $class === Vendor::class && (int) $id === 10 ? $vendor : null);

        $res = $this->call($admin, 'POST', '/v3/admin/cashback/rules', [
            'name' => 'Chic 5%',
            'reward_type' => 'percentage',
            'reward_value' => 5,
            'scope' => 'stores',
            'is_active' => true,
            'allow_with_promo' => true,
            'hold_days' => 0,
            'targets' => [['target_type' => 'vendor', 'target_id' => 10]],
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $d = $this->jsonBody($res)['data'];
        self::assertSame(77, $d['id']);
        self::assertSame('5.00', $d['reward_value']);
        self::assertSame('stores', $d['scope']);
        self::assertSame(0, $d['hold_days']);
        self::assertSame(365, $d['gift_card_validity_days']);
        self::assertTrue($d['allow_with_promo']);
        self::assertSame([['id' => null, 'target_type' => 'vendor', 'target_id' => 10, 'is_exclusion' => false, 'label' => 'Chic Store']], $d['targets']);
        self::assertSame('created', $this->audits[0]->getAction());
    }

    #[Test]
    public function createRejectsBadInput(): void
    {
        $admin = $this->admin();
        $this->rules->expects(self::never())->method('save');
        $this->bindEnv($admin);

        $missing = $this->call($admin, 'POST', '/v3/admin/cashback/rules', ['name' => 'X']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertArrayHasKey('reward_type', $this->jsonBody($missing)['error']['details']['fields']);

        $noTarget = $this->call($admin, 'POST', '/v3/admin/cashback/rules', ['name' => 'X', 'reward_type' => 'percentage', 'reward_value' => '5', 'scope' => 'stores']);
        self::assertSame(422, $noTarget->getStatusCode());
        self::assertArrayHasKey('targets', $this->jsonBody($noTarget)['error']['details']['fields']);

        $unknownTarget = $this->call($admin, 'POST', '/v3/admin/cashback/rules', [
            'name' => 'X', 'reward_type' => 'fixed', 'reward_value' => '10',
            'targets' => [['target_type' => 'vendor', 'target_id' => 999, 'is_exclusion' => true]],
        ]);
        self::assertSame(422, $unknownTarget->getStatusCode());

        $pct = $this->call($admin, 'POST', '/v3/admin/cashback/rules', ['name' => 'X', 'reward_type' => 'percentage', 'reward_value' => '150']);
        self::assertSame(422, $pct->getStatusCode());
    }

    #[Test]
    public function updateIsPartialAndNullClearsACap(): void
    {
        $admin = $this->admin();
        $rule = $this->rule(5, 'Old');
        $rule->setMaxRewardPerOrder('50.00');
        $this->rules->method('find')->willReturnCallback(static fn (mixed $id): ?CashbackRule => (int) $id === 5 ? $rule : null);
        $this->bindEnv($admin);

        $res = $this->call($admin, 'PUT', '/v3/admin/cashback/rules/5', ['name' => 'New', 'max_reward_per_order' => null, 'priority' => 7]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $d = $this->jsonBody($res)['data'];
        self::assertSame('New', $d['name']);
        self::assertNull($d['max_reward_per_order']);
        self::assertSame(7, $d['priority']);
        self::assertSame('5.00', $d['reward_value']);
        self::assertSame('updated', $this->audits[0]->getAction());

        self::assertSame(404, $this->call($admin, 'PUT', '/v3/admin/cashback/rules/6', ['name' => 'x'])->getStatusCode());
    }

    #[Test]
    public function activateAndDeactivate(): void
    {
        $admin = $this->admin();
        $rule = $this->rule(5, 'R');
        $rule->setActive(false);
        $this->rules->method('find')->willReturn($rule);
        $this->bindEnv($admin);

        $on = $this->jsonBody($this->call($admin, 'POST', '/v3/admin/cashback/rules/5/activate'));
        self::assertTrue($on['data']['is_active']);
        $off = $this->jsonBody($this->call($admin, 'POST', '/v3/admin/cashback/rules/5/deactivate'));
        self::assertFalse($off['data']['is_active']);
        self::assertSame('inactive', $off['data']['status']);
    }

    #[Test]
    public function deleteHardDeletesAnUnusedRuleAndOnlyDeactivatesAUsedOne(): void
    {
        $admin = $this->admin();
        $unused = $this->rule(1, 'Unused');
        $used = $this->rule(2, 'Used');
        $this->rules->method('find')->willReturnCallback(static fn (mixed $id): ?CashbackRule => (int) $id === 1 ? $unused : ((int) $id === 2 ? $used : null));
        $this->rules->method('isUsed')->willReturnCallback(static fn (CashbackRule $r): bool => $r === $used);
        $this->bindEnv($admin);

        self::assertSame(204, $this->call($admin, 'DELETE', '/v3/admin/cashback/rules/1')->getStatusCode());
        self::assertSame([$unused], $this->removed);

        self::assertSame(204, $this->call($admin, 'DELETE', '/v3/admin/cashback/rules/2')->getStatusCode());
        self::assertSame([$unused], $this->removed);
        self::assertFalse($used->isActive());
    }

    // ── awards ──────────────────────────────────────────────────────

    #[Test]
    public function listAwardsForwardsFilters(): void
    {
        $admin = $this->admin();
        $award = $this->award();
        $this->awards->expects(self::once())->method('findFilteredPaginated')
            ->with(self::callback(static fn (array $f): bool => $f['status'] === 'pending' && $f['rule_id'] === 3 && $f['order_reference'] === '3B-1' && $f['date_to']->format('H:i:s') === '23:59:59'))
            ->willReturn(['items' => [$award], 'total' => 1]);
        $this->bindEnv($admin);

        $res = $this->call($admin, 'GET', '/v3/admin/cashback/awards?status=pending&rule_id=3&order_reference=3B-1&date_to=2026-11-30');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $row = $this->jsonBody($res)['data'][0];
        self::assertSame(900, $row['id']);
        self::assertSame('10.00', $row['amount']);
        self::assertSame('3B-AWARD1', $row['order']['order_reference']);
        self::assertSame(55, $row['customer']['id']);
        self::assertNull($row['gift_card']);
        self::assertSame([['id' => 3, 'name' => 'Rule 3']], $row['rules']);
    }

    #[Test]
    public function releaseMapsServiceOutcomes(): void
    {
        $admin = $this->admin();
        $award = $this->award();
        $this->awards->method('find')->willReturn($award);
        $svc = $this->createMock(CashbackService::class);
        $svc->method('releaseAwardById')->willReturnOnConsecutiveCalls(
            CashbackService::OUTCOME_ISSUED,
            CashbackService::OUTCOME_HELD_RETURN,
            CashbackService::OUTCOME_NOT_PENDING,
        );
        $this->bindEnv($admin);
        $this->bind(CashbackService::class, $svc);

        $ok = $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/release', ['reason' => 'VIP']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('issued', $this->jsonBody($ok)['outcome']);

        $held = $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/release', ['reason' => 'VIP']);
        self::assertSame(422, $held->getStatusCode());
        self::assertSame('CASHBACK_RETURN_OPEN', $this->jsonBody($held)['error']['code']);

        $gone = $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/release', ['reason' => 'VIP']);
        self::assertSame(409, $gone->getStatusCode());

        self::assertSame(422, $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/release', [])->getStatusCode());
    }

    #[Test]
    public function cancelNeedsAPendingAwardAndAReason(): void
    {
        $admin = $this->admin();
        $award = $this->award();
        $this->awards->method('find')->willReturn($award);
        $svc = $this->createMock(CashbackService::class);
        $svc->method('cancelAward')->willReturnOnConsecutiveCalls(true, false);
        $this->bindEnv($admin);
        $this->bind(CashbackService::class, $svc);

        self::assertSame(422, $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/cancel', ['reason' => ''])->getStatusCode());
        self::assertSame(200, $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/cancel', ['reason' => 'fraud'])->getStatusCode());
        self::assertSame(409, $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/cancel', ['reason' => 'fraud'])->getStatusCode());
    }

    #[Test]
    public function reverseRequiresAnIssuedAwardAndReportsTheClawback(): void
    {
        $admin = $this->admin();
        $pending = $this->award();
        $this->awards->method('find')->willReturn($pending);
        $svc = $this->createMock(CashbackService::class);
        $svc->expects(self::never())->method('reverseAward');
        $this->bindEnv($admin);
        $this->bind(CashbackService::class, $svc);

        $res = $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/reverse', ['reason' => 'fraud']);
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('CASHBACK_AWARD_NOT_ISSUED', $this->jsonBody($res)['error']['code']);
    }

    #[Test]
    public function reverseAnIssuedAward(): void
    {
        $admin = $this->admin();
        $award = $this->award();
        $card = \Bayti\Api\Domain\GiftCard\GiftCard::issueCashback($award->getUser(), '10.00', new \DateTimeImmutable('+1 year'), 900, '3B-AWARD1');
        $award->markIssued($card, new \DateTimeImmutable());
        $this->awards->method('find')->willReturn($award);
        $svc = $this->createMock(CashbackService::class);
        $svc->expects(self::once())->method('reverseAward')
            ->with($award, 'Reversed by admin: fraud', self::isInstanceOf(User::class), '4.00')
            ->willReturn(['value' => '4.00', 'collected' => '1.50']);
        $this->bindEnv($admin);
        $this->bind(CashbackService::class, $svc);

        $res = $this->call($admin, 'POST', '/v3/admin/cashback/awards/900/reverse', ['reason' => 'fraud', 'amount' => '4']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['value' => '4.00', 'collected' => '1.50', 'written_off' => '2.50'], $this->jsonBody($res)['reversal']);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * @param (callable(string, mixed): ?object)|null $find
     */
    private function bindEnv(User $user, ?callable $find = null): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $sink = &$this->audits;
        $auditRepo = new class ($sink) extends \Doctrine\ORM\EntityRepository {
            /** @param list<AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $removed = &$this->removed;
        $em = $this->stubEm(function ($em) use ($userRepo, $auditRepo, $find, &$removed): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [CashbackRule::class, $this->rules],
                [CashbackAward::class, $this->awards],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('find')->willReturnCallback($find ?? static fn (): ?object => null);
            $em->method('remove')->willReturnCallback(static function (object $o) use (&$removed): void {
                $removed[] = $o;
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
        $this->bind(CashbackSettingsStore::class, $this->settings);
    }

    /** @param array<string, mixed> $body */
    private function call(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $pair = $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, $body, ['Authorization' => 'Bearer ' . $pair->accessToken]));
    }

    private function admin(): User
    {
        $u = $this->makeUser(id: 99);
        $u->setRoles(admin: true);
        return $u;
    }

    /** @param list<string> $permissions */
    private function staff(int $id, array $permissions): User
    {
        $u = $this->makeUser(id: $id);
        $role = new Role('role-' . $id, 'Role ' . $id);
        foreach ($permissions as $key) {
            $role->addPermission(new Permission($key, explode('.', $key)[0], $key));
        }
        $u->addRole($role);
        return $u;
    }

    private function rule(int $id, string $name): CashbackRule
    {
        $r = new CashbackRule($name, CashbackRule::REWARD_PERCENTAGE, '5.00');
        $r->setActive(true);
        $this->setId($r, $id);
        return $r;
    }

    private function award(): CashbackAward
    {
        $customer = $this->makeUser(id: 55, email: 'cust@example.com');
        $order = new Order($customer, '3B-AWARD1', '200.00');
        $this->setId($order, 501);
        $award = new CashbackAward($order, $customer, '10.00', 'AED', 14, 365);
        $award->addLine($this->rule(3, 'Rule 3'), 1, '200.00', '10.00');
        $this->setId($award, 900);
        return $award;
    }

    private function setId(object $o, int $id): void
    {
        (new \ReflectionProperty($o, 'id'))->setValue($o, $id);
    }
}
