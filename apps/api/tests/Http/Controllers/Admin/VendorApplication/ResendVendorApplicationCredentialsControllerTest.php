<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\VendorApplication;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\Catalog\VendorApplicationRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\VendorApplication\ResendVendorApplicationCredentialsController;
use Bayti\Api\Http\Serializers\VendorApplicationSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\VendorApplicationWelcomeMailer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/admin/vendor-applications/{id}/resend-credentials
 *
 * Focus of these tests: the resent credential must land on the account a LOGIN
 * for the application email actually authenticates against (findByEmail =
 * non-deleted, case-insensitive). The bug this guards against: resend resolved
 * the seller via vendor->getOwnerUser(), which can be a soft-deleted or stale
 * row that login can never reach — so the emailed password 401'd at /auth/login
 * even though it was "correct".
 */
#[CoversClass(ResendVendorApplicationCredentialsController::class)]
#[CoversClass(VendorApplicationWelcomeMailer::class)]
final class ResendVendorApplicationCredentialsControllerTest extends HttpTestCase
{
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new InMemoryMailer();
    }

    #[Test]
    public function resetsTheLoginAccountAndEmailsAWorkingTempPassword(): void
    {
        $admin = $this->makeAdminUser(99);
        $loginUser = $this->makeSeller(55, 'seller@bayti.example', 'oldPass!');
        $oldHash = $loginUser->getPasswordHash();
        $app = $this->approvedApplication(7, 'seller@bayti.example', $this->makeVendor(42, $loginUser));

        $this->bindEm($admin, $app, ['seller@bayti.example' => $loginUser]);

        $response = $this->post($admin, 7);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('seller@bayti.example', $body['sent_to']);

        // A fresh temp password was set + the vendor must change it on sign-in.
        self::assertNotSame($oldHash, $loginUser->getPasswordHash());
        self::assertTrue($loginUser->mustChangePassword());

        // The password in the email is EXACTLY the one that now verifies —
        // i.e. logging in with it would succeed (the regression that failed).
        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('seller@bayti.example', $sent[0]['to']);
        self::assertSame(1, preg_match('/Password: (\S+)/', $sent[0]['text_body'], $m));
        self::assertTrue(password_verify($m[1], (string) $loginUser->getPasswordHash()));
    }

    #[Test]
    public function resetsLoginAccountNotAStaleOwnerLink(): void
    {
        // The application's vendor is linked to a DIFFERENT (stale) owner row,
        // but a live account exists for the application email. The reset MUST
        // land on the login account, not the stale owner — otherwise the email
        // hands out a password login can never accept.
        $admin = $this->makeAdminUser(99);
        $loginUser = $this->makeSeller(55, 'seller@bayti.example', 'oldPass!');
        $staleOwner = $this->makeSeller(77, 'old-owner@bayti.example', 'stalePass!');
        $staleHash = $staleOwner->getPasswordHash();
        $app = $this->approvedApplication(7, 'seller@bayti.example', $this->makeVendor(42, $staleOwner));

        $this->bindEm($admin, $app, ['seller@bayti.example' => $loginUser]);

        $response = $this->post($admin, 7);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('seller@bayti.example', $this->jsonBody($response)['sent_to']);
        // Login account reset, stale owner untouched.
        self::assertTrue($loginUser->mustChangePassword());
        self::assertSame($staleHash, $staleOwner->getPasswordHash());
        self::assertSame('seller@bayti.example', $this->mailer->sent()[0]['to']);
    }

    #[Test]
    public function refusesWhenNoLoginReachableAccountExists(): void
    {
        // No live account for the application email, and the linked owner is
        // soft-deleted (login's findByEmail filters deleted_at IS NULL, so it
        // could never sign in). Resend must REFUSE + send nothing, instead of
        // silently emailing a dead credential.
        $admin = $this->makeAdminUser(99);
        $deletedOwner = $this->makeSeller(77, 'seller@bayti.example', 'oldPass!');
        $this->softDelete($deletedOwner);
        $deletedHash = $deletedOwner->getPasswordHash();
        $app = $this->approvedApplication(7, 'seller@bayti.example', $this->makeVendor(42, $deletedOwner));

        // findByEmail resolves nothing (the only row is soft-deleted).
        $this->bindEm($admin, $app, []);

        $response = $this->post($admin, 7);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->mailer->sent());
        self::assertSame($deletedHash, $deletedOwner->getPasswordHash());
    }

    #[Test]
    public function refusesForANonApprovedApplication(): void
    {
        $admin = $this->makeAdminUser(99);
        $app = new VendorApplication('Rahab', 'Adduja', 'seller@bayti.example', '+971500000000', 'Lamaat Adduja');
        $this->setId($app, 7); // stays pending

        $this->bindEm($admin, $app, ['seller@bayti.example' => $this->makeSeller(55, 'seller@bayti.example', 'x')]);

        self::assertSame(422, $this->post($admin, 7)->getStatusCode());
        self::assertSame([], $this->mailer->sent());
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id, email: "admin{$id}@bayti.example");
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeSeller(int $id, string $email, string $password): User
    {
        $user = $this->makeUser(id: $id, email: $email, passwordPlain: $password);
        $user->setRoles(vendor: true);
        $user->setName('Rahab', 'Adduja');
        return $user;
    }

    private function makeVendor(int $id, User $owner): Vendor
    {
        $vendor = new Vendor('lamaat-adduja', 'Lamaat Adduja', 'contact@bayti.example');
        $this->setId($vendor, $id);
        $vendor->setOwnerUser($owner);
        return $vendor;
    }

    private function approvedApplication(int $id, string $email, Vendor $vendor): VendorApplication
    {
        $reviewer = $this->makeUser(id: 1, email: 'reviewer@bayti.example');
        $app = new VendorApplication('Rahab', 'Adduja', $email, '+971500000000', 'Lamaat Adduja');
        $this->setId($app, $id);
        $app->markApproved($vendor, $reviewer);
        return $app;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function softDelete(User $user): void
    {
        $ref = new \ReflectionProperty(User::class, 'deletedAt');
        $ref->setAccessible(true);
        $ref->setValue($user, new \DateTimeImmutable());
    }

    /**
     * @param array<string, User> $usersByEmail  findByEmail lookup table
     */
    private function bindEm(User $caller, VendorApplication $app, array $usersByEmail): void
    {
        $appRepo = $this->createMock(VendorApplicationRepository::class);
        $appRepo->method('findById')->willReturnCallback(
            fn (int $id): ?VendorApplication => $app->getId() === $id ? $app : null,
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturnCallback(
            static fn (string $email): ?User => $usersByEmail[strtolower(trim($email))] ?? null,
        );
        // The AuthMiddleware resolves the caller by id from its JWT.
        $userRepo->method('findById')->willReturnCallback(
            fn (int $id): ?User => $id === $caller->getId() ? $caller : null,
        );

        $em = $this->stubEm(function ($em) use ($appRepo, $userRepo): void {
            $em->method('getRepository')->willReturnMap([
                [VendorApplication::class, $appRepo],
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(VendorApplicationSerializer::class, new VendorApplicationSerializer());
        $this->bind(
            VendorApplicationWelcomeMailer::class,
            new VendorApplicationWelcomeMailer($this->mailer, new NullLogger()),
        );
    }

    private function post(User $user, int $applicationId): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest(
            'POST',
            "/v3/admin/vendor-applications/{$applicationId}/resend-credentials",
            [],
            ['Authorization' => 'Bearer ' . $pair->accessToken],
        ));
    }
}
