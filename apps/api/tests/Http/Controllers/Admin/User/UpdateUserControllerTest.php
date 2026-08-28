<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\User;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\User\Dto\UpdateUserInput;
use Bayti\Api\Http\Controllers\Admin\User\UpdateUserController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Coverage for PUT /v3/admin/users/{id} (admin support-edit of a user's
 * contact details).
 *
 * Verifies:
 *   - Name / email / phone are updated; changing email or phone resets its
 *     verified flag.
 *   - An unchanged email keeps its verified flag (no spurious unverify).
 *   - A taken email / phone returns 409; the entity is not mutated to the
 *     conflicting value.
 *   - A blank phone clears it.
 *   - Unknown id → 404, invalid email → 422, non-admin → 403.
 */
#[CoversClass(UpdateUserController::class)]
#[CoversClass(UpdateUserInput::class)]
final class UpdateUserControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function updatesContactDetailsAndResetsEmailAndPhoneVerification(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $target->markEmailVerified();
        self::assertTrue($target->isEmailVerified());
        self::assertTrue($target->isPhoneVerified());

        $this->bindEm($admin, $target);

        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'first_name' => 'Newfirst',
            'last_name' => 'Newlast',
            'email' => 'moved@bayti.example',
            'phone' => '+971509999999',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('moved@bayti.example', $target->getEmail());
        self::assertSame('Newfirst', $target->getFirstName());
        self::assertSame('Newlast', $target->getLastName());
        self::assertSame('+971509999999', $target->getPhone());
        self::assertFalse($target->isEmailVerified(), 'changed email is unverified');
        self::assertFalse($target->isPhoneVerified(), 'changed phone is unverified');

        $body = $this->jsonBody($response);
        self::assertSame('moved@bayti.example', $body['data']['email'] ?? null);
    }

    #[Test]
    public function keepsEmailVerifiedWhenEmailUnchanged(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $target->markEmailVerified();

        $this->bindEm($admin, $target);

        // Same email + same phone → nothing about contact changes.
        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'first_name' => 'Same',
            'last_name' => 'Person',
            'email' => 'target@bayti.example',
            'phone' => '+971501234567',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($target->isEmailVerified(), 'unchanged email stays verified');
        self::assertTrue($target->isPhoneVerified(), 'unchanged phone stays verified');
    }

    #[Test]
    public function returns409WhenEmailTakenByAnotherAccount(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $this->bindEm($admin, $target, emailAvailable: false);

        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'email' => 'someoneelse@bayti.example',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('target@bayti.example', $target->getEmail(), 'email not changed on conflict');
    }

    #[Test]
    public function returns409WhenPhoneTakenByAnotherAccount(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $this->bindEm($admin, $target, phoneAvailable: false);

        // Email unchanged so only the phone-availability path is exercised.
        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'email' => 'target@bayti.example',
            'phone' => '+971500000000',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('+971501234567', $target->getPhone(), 'phone not changed on conflict');
    }

    #[Test]
    public function clearsPhoneWhenBlank(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $this->bindEm($admin, $target);

        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'email' => 'target@bayti.example',
            'phone' => '',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($target->getPhone(), 'blank phone clears it');
    }

    #[Test]
    public function returns404ForUnknownUser(): void
    {
        $admin = $this->makeAdmin();
        // No target bound (findById returns the admin only for its own id).
        $this->bindEm($admin, target: null);

        $response = $this->makePut($admin, '/v3/admin/users/9999', [
            'email' => 'whoever@bayti.example',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns422OnInvalidEmail(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $this->bindEm($admin, $target);

        $response = $this->makePut($admin, '/v3/admin/users/55', [
            'email' => 'not-an-email',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function forbiddenForNonAdminWithoutPermission(): void
    {
        $customer = $this->makeUser(id: 7, email: 'cust@bayti.example');
        $target = $this->makeUser(id: 55, email: 'target@bayti.example');
        $this->bindEm($customer, $target);

        $response = $this->makePut($customer, '/v3/admin/users/55', [
            'email' => 'moved@bayti.example',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function makeAdmin(int $id = 99): User
    {
        $admin = $this->makeUser(id: $id, email: 'admin@bayti.example');
        $admin->setRoles(admin: true);
        return $admin;
    }

    private function bindEm(
        User $caller,
        ?User $target,
        bool $emailAvailable = true,
        bool $phoneAvailable = true,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        // Caller resolved by AuthMiddleware (its id); target by the controller
        // (route id). Distinguish by id so both resolve correctly.
        $userRepo->method('findById')->willReturnCallback(
            static function (int $id) use ($caller, $target): ?User {
                if ($id === $caller->getId()) {
                    return $caller;
                }
                return $target !== null && $id === $target->getId() ? $target : null;
            },
        );
        $userRepo->method('isEmailAvailable')->willReturn($emailAvailable);
        $userRepo->method('isPhoneAvailable')->willReturn($phoneAvailable);

        // UserSerializer::publicProfile derives store flags from the user's
        // vendors; a customer has none.
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int, AuditLog> $sink */
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $emitter = new AuditEmitter($em, new NullLogger());
        $this->bind(AuditEmitter::class, $emitter);
    }

    /** @param array<string, mixed> $body */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PUT', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
