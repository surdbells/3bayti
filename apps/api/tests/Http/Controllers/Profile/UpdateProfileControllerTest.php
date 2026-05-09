<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\Dto\UpdateProfileInput;
use Bayti\Api\Http\Controllers\Profile\UpdateProfileController;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for PATCH /v3/me/profile.
 *
 * Because the controller writes to the EntityManager (em->flush()),
 * each test that updates the user installs a mock EM and verifies
 * flush() is called the expected number of times.
 *
 * Important: the User entity is the same instance we returned from
 * findById, so the controller's setters mutate it in place. We can
 * assert post-state directly on $user without round-tripping through
 * the repo.
 */
#[CoversClass(UpdateProfileController::class)]
#[CoversClass(UpdateProfileInput::class)]
#[CoversClass(UserSerializer::class)]
final class UpdateProfileControllerTest extends HttpTestCase
{
    #[Test]
    public function updatesProvidedFieldsOnly(): void
    {
        $user = $this->makeUser(id: 9, email: 'bob@example.com');
        $user->setName('OldFirst', 'OldLast');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(9)->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->with(User::class)->willReturn($userRepo);
            // Exactly one flush expected.
            $em->expects(self::once())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'first_name' => 'NewFirst',
                // last_name omitted — should stay 'OldLast'
                'gender' => 'female',
                'locale' => 'ar-AE',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        // Direct entity assertions — the controller mutated $user in place.
        self::assertSame('NewFirst', $user->getFirstName());
        self::assertSame('OldLast', $user->getLastName());
        self::assertSame('female', $user->getGender());
        self::assertSame('ar-AE', $user->getLocale());

        // Response payload also reflects the new values.
        $body = $this->jsonBody($response);
        self::assertSame('NewFirst', $body['user']['first_name']);
        self::assertSame('OldLast', $body['user']['last_name']);
        self::assertSame('female', $body['user']['gender']);
        self::assertSame('ar-AE', $body['user']['locale']);
    }

    #[Test]
    public function emptyBodyReturns200NoOp(): void
    {
        $user = $this->makeUser(id: 10);
        $user->setName('Original', 'Name');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(10)->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->with(User::class)->willReturn($userRepo);
            // Empty body must NOT trigger a flush.
            $em->expects(self::never())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        // Returns the unchanged profile.
        self::assertSame('Original', $body['user']['first_name']);
        self::assertSame('Name', $body['user']['last_name']);
    }

    #[Test]
    public function setsDobCorrectly(): void
    {
        $user = $this->makeUser(id: 11);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturn($userRepo);
            $em->expects(self::once())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'dob' => '1990-03-14',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('1990-03-14', $body['user']['dob']);

        // Entity has the date with midnight time.
        $dob = $user->getDob();
        self::assertNotNull($dob);
        self::assertSame('1990-03-14', $dob->format('Y-m-d'));
        self::assertSame('00:00:00', $dob->format('H:i:s'));
    }

    #[Test]
    public function rejectsTooLongFirstName(): void
    {
        $user = $this->makeUser(id: 12);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'first_name' => str_repeat('A', 101),
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidGender(): void
    {
        $user = $this->makeUser(id: 13);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'gender' => 'banana',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidLocale(): void
    {
        $user = $this->makeUser(id: 14);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'locale' => 'fr-FR',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidTimezone(): void
    {
        $user = $this->makeUser(id: 15);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'timezone' => 'Mars/Phobos',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsFutureDob(): void
    {
        $user = $this->makeUser(id: 16);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $futureDate = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'dob' => $futureDate,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsImpossiblyOldDob(): void
    {
        $user = $this->makeUser(id: 17);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // 200 years ago — well beyond our 130-year cap.
        $oldDate = (new \DateTimeImmutable('-200 years'))->format('Y-m-d');

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'dob' => $oldDate,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMalformedDob(): void
    {
        $user = $this->makeUser(id: 18);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'dob' => '14/03/1990', // wrong format
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', ['first_name' => 'X'])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function trimsWhitespaceFromInput(): void
    {
        $user = $this->makeUser(id: 19);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturn($userRepo);
            $em->expects(self::once())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/profile', [
                'first_name' => '  Alice  ',
                'last_name' => '  Smith  ',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Alice', $user->getFirstName());
        self::assertSame('Smith', $user->getLastName());
    }
}
