<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserLocation;
use Bayti\Api\Domain\User\UserLocationRepository;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\Dto\UpdateLocationInput;
use Bayti\Api\Http\Controllers\Profile\UpdateLocationController;
use Bayti\Api\Http\Serializers\UserLocationSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UpdateLocationController::class)]
#[CoversClass(UpdateLocationInput::class)]
#[CoversClass(UserLocationSerializer::class)]
final class UpdateLocationControllerTest extends HttpTestCase
{
    // ---------------------------------------------------------------
    // CREATE path tests (no existing UserLocation row)
    // ---------------------------------------------------------------

    #[Test]
    public function returns200AndCreatesLocationWithCoordinates(): void
    {
        $user = $this->makeUser(id: 80);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $locRepo = $this->createMock(UserLocationRepository::class);
        $locRepo->method('findForUser')->with($user)->willReturn(null);
        $locRepo->expects(self::once())->method('save')->with(
            self::callback(function (UserLocation $loc) {
                return $loc->getLatitude() === '25.276987'
                    && $loc->getLongitude() === '55.296249'
                    && $loc->isPermissionGranted() === true;
            })
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $locRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [UserLocation::class, $locRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'latitude' => 25.276987,
                'longitude' => 55.296249,
                'permission_granted' => true,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        // Response wraps the location data, see controller docblock
        // for why we deviate from 0e.2 contract (which said 'user').
        self::assertArrayHasKey('location', $body);
        self::assertSame(25.276987, $body['location']['latitude']);
        self::assertSame(55.296249, $body['location']['longitude']);
        self::assertTrue($body['location']['permission_granted']);
    }

    #[Test]
    public function returns200AndCreatesPermissionDenialRecord(): void
    {
        $user = $this->makeUser(id: 81);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $locRepo = $this->createMock(UserLocationRepository::class);
        $locRepo->method('findForUser')->with($user)->willReturn(null);
        $locRepo->expects(self::once())->method('save')->with(
            self::callback(function (UserLocation $loc) {
                // OS-permission denial: record the decision, no coords.
                return $loc->isPermissionGranted() === false
                    && $loc->getLatitude() === null
                    && $loc->getLongitude() === null;
            })
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $locRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [UserLocation::class, $locRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'permission_granted' => false,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertFalse($body['location']['permission_granted']);
        self::assertNull($body['location']['latitude']);
    }

    #[Test]
    public function returns200AndCreatesManualCityEntry(): void
    {
        $user = $this->makeUser(id: 82);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $locRepo = $this->createMock(UserLocationRepository::class);
        $locRepo->method('findForUser')->with($user)->willReturn(null);
        $locRepo->expects(self::once())->method('save')->with(
            self::callback(function (UserLocation $loc) {
                return $loc->getCity() === 'Dubai'
                    && $loc->getCountryCode() === 'AE';
            })
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $locRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [UserLocation::class, $locRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'city' => 'Dubai',
                'country_code' => 'ae',  // lowercase on input
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        // Country code uppercased server-side.
        self::assertSame('AE', $body['location']['country_code']);
    }

    // ---------------------------------------------------------------
    // UPDATE path tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns200AndUpdatesExistingLocation(): void
    {
        $user = $this->makeUser(id: 83);
        $existing = $this->makeLocation($user, id: 700, city: 'Sharjah');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $locRepo = $this->createMock(UserLocationRepository::class);
        $locRepo->method('findForUser')->with($user)->willReturn($existing);
        $locRepo->expects(self::never())->method('save');  // UPDATE uses em->flush()

        $em = $this->stubEm(function ($em) use ($userRepo, $locRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [UserLocation::class, $locRepo],
            ]);
            $em->expects(self::atLeastOnce())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'city' => 'Dubai',  // change from Sharjah
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Dubai', $body['location']['city']);
    }

    #[Test]
    public function emptyBodyIsValidNoop(): void
    {
        $user = $this->makeUser(id: 84);
        $existing = $this->makeLocation($user, id: 701);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $locRepo = $this->createMock(UserLocationRepository::class);
        $locRepo->method('findForUser')->with($user)->willReturn($existing);

        $em = $this->stubEm(function ($em) use ($userRepo, $locRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [UserLocation::class, $locRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // No-op PATCH still returns 200 + current state.
        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Validation tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns422WhenLatitudeOutOfRange(): void
    {
        $user = $this->makeUser(id: 85);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'latitude' => 95.0,  // outside ±90
                'longitude' => 55.0,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    #[Test]
    public function returns422WhenOnlyLatitudeProvided(): void
    {
        $user = $this->makeUser(id: 86);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'latitude' => 25.0,  // longitude missing, partial coords
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns422WhenCountryCodeIsNotTwoLetters(): void
    {
        $user = $this->makeUser(id: 87);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', [
                'country_code' => 'UAE',  // 3 chars, not ISO alpha-2
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Auth tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/location', ['city' => 'Dubai'])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeLocation(
        User $user,
        int $id,
        ?string $city = null,
    ): UserLocation {
        $loc = new UserLocation($user);
        if ($city !== null) {
            $loc->update(city: $city);
        }
        $ref = new \ReflectionProperty(UserLocation::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($loc, $id);
        return $loc;
    }
}
