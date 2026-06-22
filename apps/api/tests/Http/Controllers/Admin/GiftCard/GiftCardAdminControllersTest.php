<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\GiftCard;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\GiftCard\GiftCardTransaction;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\GiftCard\AdjustGiftCardController;
use Bayti\Api\Http\Controllers\Admin\GiftCard\GetGiftCardController;
use Bayti\Api\Http\Controllers\Admin\GiftCard\IssueGiftCardController;
use Bayti\Api\Http\Controllers\Admin\GiftCard\ListGiftCardsController;
use Bayti\Api\Http\Controllers\Admin\GiftCard\VoidGiftCardController;
use Bayti\Api\Http\Controllers\GiftCard\GiftCardSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for the M5 admin gift-card surface.
 *
 * Endpoints:
 *   GET  /v3/admin/gift-cards
 *   GET  /v3/admin/gift-cards/{id}
 *   POST /v3/admin/gift-cards/{id}/adjust
 *   POST /v3/admin/gift-cards/{id}/void
 *   POST /v3/admin/gift-cards
 *
 * Mirrors PromoCodeAdminControllersTest's harness: full Slim app, real
 * admin JWT, mocked EM with a getRepository return-map, AuditLog repo
 * capturing into a sink.
 */
#[CoversClass(ListGiftCardsController::class)]
#[CoversClass(GetGiftCardController::class)]
#[CoversClass(AdjustGiftCardController::class)]
#[CoversClass(VoidGiftCardController::class)]
#[CoversClass(IssueGiftCardController::class)]
#[CoversClass(GiftCardSerializer::class)]
final class GiftCardAdminControllersTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    // ===================== LIST =====================

    #[Test]
    public function listReturnsPaginatedAdminShapeAndForwardsFilters(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 1, value: '500.00');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->expects(self::once())
            ->method('findPaginatedForAdmin')
            ->with(self::callback(function (array $f): bool {
                return ($f['status'] ?? null) === GiftCard::STATUS_ACTIVE
                    && ($f['search'] ?? null) === 'sara'
                    && ($f['delivered'] ?? null) === true
                    && ($f['minBalance'] ?? null) === '100.00'
                    && ($f['limit'] ?? null) === 10
                    && ($f['offset'] ?? null) === 20;
            }))
            ->willReturn(['items' => [$card], 'total' => 1]);

        $this->bindEm($admin, $repo);
        $response = $this->makeGet(
            $admin,
            '/v3/admin/gift-cards?status=active&search=sara&delivered=true&min_balance=100.00&limit=10&offset=20',
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(1, $body['meta']['total']);
        self::assertCount(1, $body['data']);
        self::assertSame('500.00', $body['data'][0]['denomination']);
        self::assertArrayHasKey('purchaser', $body['data'][0]);
        self::assertArrayHasKey('is_delivered', $body['data'][0]);
        // Lean list shape: no ledger.
        self::assertArrayNotHasKey('ledger', $body['data'][0]);
    }

    #[Test]
    public function listRejectsInvalidStatusWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $repo = $this->createMock(GiftCardRepository::class);
        $this->bindEm($admin, $repo);

        $response = $this->makeGet($admin, '/v3/admin/gift-cards?status=nonsense');
        self::assertSame(422, $response->getStatusCode());
    }

    // ===================== DETAIL =====================

    #[Test]
    public function detailReturnsCurrentBalanceAndOrderedLedger(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '500.00');
        // Apply a debit so the ledger has a row + balance moves.
        $card->debit('200.00', 'ORDER-XYZ');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makeGet($admin, '/v3/admin/gift-cards/7');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(7, $body['data']['id']);
        self::assertSame('300.00', $body['data']['balance']);
        self::assertArrayHasKey('ledger', $body['data']);
        self::assertArrayHasKey('delivery', $body['data']);
        self::assertCount(1, $body['data']['ledger']);
        self::assertSame('debit', $body['data']['ledger'][0]['type']);
        self::assertSame('200.00', $body['data']['ledger'][0]['amount']);
        self::assertSame('300.00', $body['data']['ledger'][0]['balance_after']);
    }

    #[Test]
    public function detailReturns404ForMissingId(): void
    {
        $admin = $this->makeAdminUser(99);
        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->willReturn(null);

        $this->bindEm($admin, $repo);
        self::assertSame(404, $this->makeGet($admin, '/v3/admin/gift-cards/999')->getStatusCode());
    }

    // ===================== ADJUST =====================

    #[Test]
    public function adjustCreditWritesLedgerRowAndUpdatesBalance(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/adjust', [
            'amount' => '50.00',
            'reason' => 'Goodwill credit',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('150.00', $body['data']['balance']);
        self::assertSame('credit', $body['data']['transaction']['type']);
        self::assertSame('50.00', $body['data']['transaction']['amount']);
        self::assertSame('150.00', $body['data']['transaction']['balance_after']);
        self::assertSame('Goodwill credit', $body['data']['transaction']['reason']);
        self::assertSame(99, $body['data']['transaction']['actor_user_id']);

        // Entity-side: balance updated + a ledger row appended.
        self::assertSame('150.00', $card->getBalance());
        self::assertCount(1, $card->getTransactions());
    }

    #[Test]
    public function adjustSignedNegativeAmountDebits(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/adjust', [
            'amount' => '-30.00',
            'reason' => 'Manual deduction',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('debit', $body['data']['transaction']['type']);
        self::assertSame('70.00', $body['data']['balance']);
    }

    #[Test]
    public function adjustRejectsOverdrawWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/adjust', [
            'type' => 'debit',
            'amount' => '500.00',  // exceeds 100 balance
            'reason' => 'Too much',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('gift_card_overdraw', $this->jsonBody($response)['error']['code']);
        // Balance untouched + no ledger row.
        self::assertSame('100.00', $card->getBalance());
        self::assertCount(0, $card->getTransactions());
    }

    #[Test]
    public function adjustRequiresReasonWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');
        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/adjust', ['amount' => '10.00']);
        self::assertSame(422, $response->getStatusCode());
    }

    // ===================== VOID =====================

    #[Test]
    public function voidSetsStatusZeroesBalanceAndRecordsLedger(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/void', ['reason' => 'Fraud']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(GiftCard::STATUS_VOIDED, $body['data']['status']);
        self::assertSame('0.00', $body['data']['balance']);
        self::assertFalse($body['data']['already_voided']);
        self::assertSame('void', $body['data']['transaction']['type']);

        self::assertSame(GiftCard::STATUS_VOIDED, $card->getStatus());
        self::assertSame('0.00', $card->getBalance());
    }

    #[Test]
    public function voidIsIdempotentForAlreadyVoidedCard(): void
    {
        $admin = $this->makeAdminUser(99);
        $card = $this->makeActiveCard(id: 7, value: '100.00');
        $card->voidWithLedger('first void', 1); // already voided

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('findByIdForAdmin')->with(7)->willReturn($card);

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards/7/void', ['reason' => 'again']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertTrue($body['data']['already_voided']);
        self::assertNull($body['data']['transaction']);
        // No second void ledger row appended.
        self::assertCount(1, $card->getTransactions());
        // No audit override recorded for the no-op.
        self::assertCount(0, $this->recordedAuditLogs);
    }

    // ===================== ISSUE =====================

    #[Test]
    public function issueCreatesActiveCardWithOpeningCreditLedger(): void
    {
        $admin = $this->makeAdminUser(99);

        $saved = [];
        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('save')->willReturnCallback(function (GiftCard $c) use (&$saved): void {
            $this->setEntityId($c, 321);
            $saved[] = $c;
        });

        $this->bindEm($admin, $repo);
        $response = $this->makePost($admin, '/v3/admin/gift-cards', [
            'value' => '250.00',
            'recipient_email' => 'gift@example.com',
            'recipient_name' => 'Lena',
            'note' => 'Comp for support issue',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(GiftCard::STATUS_ACTIVE, $body['data']['status']);
        self::assertSame('250.00', $body['data']['balance']);
        self::assertSame('250.00', $body['data']['denomination']);
        // Opening ledger row = the issued value as a credit.
        self::assertCount(1, $body['data']['ledger']);
        self::assertSame('credit', $body['data']['ledger'][0]['type']);
        self::assertSame('250.00', $body['data']['ledger'][0]['amount']);
        // Unique code present + formatted.
        self::assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $body['data']['code']);

        self::assertCount(1, $saved);
        self::assertCount(1, $this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_CREATED, $this->recordedAuditLogs[0]->getAction());
    }

    #[Test]
    public function issueRejectsValueBelowMinimumWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $repo = $this->createMock(GiftCardRepository::class);
        $repo->expects(self::never())->method('save');
        $this->bindEm($admin, $repo);

        $response = $this->makePost($admin, '/v3/admin/gift-cards', ['value' => '5.00']);
        self::assertSame(422, $response->getStatusCode());
    }

    // ===================== AUTH =====================

    #[Test]
    public function requiresAdminRole(): void
    {
        $regular = $this->makeUser(id: 7);
        $repo = $this->createMock(GiftCardRepository::class);
        $this->bindEm($regular, $repo);

        self::assertSame(403, $this->makeGet($regular, '/v3/admin/gift-cards')->getStatusCode());
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/gift-cards'));
        self::assertSame(401, $response->getStatusCode());
    }

    // ===================== Helpers =====================

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeActiveCard(int $id, string $value): GiftCard
    {
        $buyer = $this->makeUser(id: 1000 + $id, email: "buyer{$id}@example.com");
        $card = new GiftCard(
            buyerUser: $buyer,
            denomination: $value,
            theme: GiftCard::THEME_BIRTHDAY,
            recipientName: 'Sara',
            recipientMessage: null,
            recipientPhotoUrl: null,
            scheduledDeliveryAt: null,
            recipientEmail: 'sara@example.com',
            recipientPhone: null,
        );
        $card->activate('GC-ORDER-' . $id);
        $this->setEntityId($card, $id);
        return $card;
    }

    private function bindEm(User $user, GiftCardRepository $repo): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $sink = &$this->recordedAuditLogs;
        $auditRepo = new class($sink) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int, AuditLog> $sink */
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $repo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [GiftCard::class, $repo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('persist');
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

    /** @param array<string,mixed> $body */
    private function makePost(User $user, string $uri, array $body): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', $uri, $body, $this->headers($user)));
    }

    /** @return array<string,string> */
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
