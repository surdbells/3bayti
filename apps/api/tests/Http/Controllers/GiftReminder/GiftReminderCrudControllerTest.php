<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\GiftReminder;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\GiftReminder\CreateGiftReminderController;
use Bayti\Api\Http\Controllers\GiftReminder\DeleteGiftReminderController;
use Bayti\Api\Http\Controllers\GiftReminder\ListGiftRemindersController;
use Bayti\Api\Http\Controllers\GiftReminder\UpdateGiftReminderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(CreateGiftReminderController::class)]
#[CoversClass(ListGiftRemindersController::class)]
#[CoversClass(UpdateGiftReminderController::class)]
#[CoversClass(DeleteGiftReminderController::class)]
final class GiftReminderCrudControllerTest extends HttpTestCase
{
    /** @var list<GiftReminder> captured saves */
    private array $saved = [];
    /** @var list<GiftReminder> captured removes */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = [];
        $this->removed = [];
    }

    #[Test]
    public function createsAReminderForTheCaller(): void
    {
        $user = $this->makeUser(id: 1);
        $this->bindDeps($user, existing: [], byId: []);

        $res = $this->authed($user, 'POST', '/v3/me/gift-reminders', [
            'recipient_name' => 'My Sister',
            'occasion' => 'Eid',
            'remind_date' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'budget_max' => '500',
            'category_slug' => 'abayas',
        ]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->body($res);
        self::assertSame('My Sister', $data['gift_reminder']['recipient_name']);
        self::assertSame('500', $data['gift_reminder']['budget_max']);
        self::assertCount(1, $this->saved);
    }

    #[Test]
    public function rejectsAPastDate(): void
    {
        $user = $this->makeUser(id: 1);
        $this->bindDeps($user, existing: [], byId: []);

        $res = $this->authed($user, 'POST', '/v3/me/gift-reminders', [
            'recipient_name' => 'My Sister',
            'occasion' => 'Eid',
            'remind_date' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
        ]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function listsOnlyTheCallersReminders(): void
    {
        $user = $this->makeUser(id: 1);
        $mine = $this->makeReminder(10, $user, 'Mum');
        $this->bindDeps($user, existing: [$mine], byId: []);

        $res = $this->authed($user, 'GET', '/v3/me/gift-reminders', []);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->body($res);
        self::assertCount(1, $data['gift_reminders']);
        self::assertSame('Mum', $data['gift_reminders'][0]['recipient_name']);
    }

    #[Test]
    public function updatesOwnReminderButNotAnothersIdor(): void
    {
        $user = $this->makeUser(id: 1);
        $other = $this->makeUser(id: 999);
        $mine = $this->makeReminder(10, $user, 'Mum');
        $theirs = $this->makeReminder(11, $other, 'Their Friend');
        $this->bindDeps($user, existing: [$mine], byId: [10 => $mine, 11 => $theirs]);

        $body = ['recipient_name' => 'Mother', 'occasion' => 'Birthday', 'remind_date' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d')];

        $ok = $this->authed($user, 'PUT', '/v3/me/gift-reminders/10', $body);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());

        $idor = $this->authed($user, 'PUT', '/v3/me/gift-reminders/11', $body);
        self::assertSame(404, $idor->getStatusCode(), (string) $idor->getBody());
    }

    #[Test]
    public function deletesOwnReminderButNotAnothersIdor(): void
    {
        $user = $this->makeUser(id: 1);
        $other = $this->makeUser(id: 999);
        $mine = $this->makeReminder(10, $user, 'Mum');
        $theirs = $this->makeReminder(11, $other, 'Their Friend');
        $this->bindDeps($user, existing: [$mine], byId: [10 => $mine, 11 => $theirs]);

        $ok = $this->authed($user, 'DELETE', '/v3/me/gift-reminders/10', []);
        self::assertSame(204, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertCount(1, $this->removed);

        $idor = $this->authed($user, 'DELETE', '/v3/me/gift-reminders/11', []);
        self::assertSame(404, $idor->getStatusCode(), (string) $idor->getBody());
    }

    // ===== helpers =====

    /**
     * @param list<GiftReminder> $existing
     * @param array<int, GiftReminder> $byId
     */
    private function bindDeps(User $user, array $existing, array $byId): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $reminderRepo = $this->createMock(GiftReminderRepository::class);
        $reminderRepo->method('countForUser')->willReturn(count($existing));
        $reminderRepo->method('findAllForUser')->willReturn($existing);
        $reminderRepo->method('find')->willReturnCallback(static fn ($id) => $byId[(int) $id] ?? null);
        $reminderRepo->method('save')->willReturnCallback(function (GiftReminder $r): void {
            $this->saved[] = $r;
        });
        $reminderRepo->method('remove')->willReturnCallback(function (GiftReminder $r): void {
            $this->removed[] = $r;
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $reminderRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [GiftReminder::class, $reminderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeReminder(int $id, User $user, string $recipient): GiftReminder
    {
        $r = new GiftReminder($user, $recipient, 'Eid', new \DateTimeImmutable('+20 days'));
        $ref = new \ReflectionProperty(GiftReminder::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($r, $id);
        return $r;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authed(User $user, string $method, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
    }
}
