<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Measurement;

use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Measurement\Dto\UpsertMeasurementsInput;
use Bayti\Api\Http\Controllers\Measurement\UpsertMeasurementsController;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UpsertMeasurementsController::class)]
#[CoversClass(UpsertMeasurementsInput::class)]
#[CoversClass(MeasurementSerializer::class)]
final class UpsertMeasurementsControllerTest extends HttpTestCase
{
    #[Test]
    public function createsNewMeasurementWhenNoneExists(): void
    {
        $user = $this->makeUser(id: 110);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->with($user, null)->willReturn(null);
        $captured = null;
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Measurement $m) use (&$captured) {
                $captured = $m;
            });

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 60.0, 'bust' => 92.0],
                'notes' => 'Default set',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($captured);
        self::assertSame(['arm' => 60.0, 'bust' => 92.0], $captured->getValues());
        self::assertSame('Default set', $captured->getNotes());
        self::assertNull($captured->getCategoryId());
    }

    #[Test]
    public function updatesExistingMeasurement(): void
    {
        $user = $this->makeUser(id: 111);
        $existing = $this->makeMeasurement($user, id: 5, categoryId: null, values: ['arm' => 50.0]);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->with($user, null)->willReturn($existing);
        // No save call, entity is updated in-place + flush.
        $repo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
            $em->expects(self::once())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 60.0, 'bust' => 92.0],
                'notes' => 'Updated',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        // In-place mutation
        self::assertSame(60.0, $existing->getValues()['arm']);
        self::assertSame(92.0, $existing->getValues()['bust']);
        self::assertSame('Updated', $existing->getNotes());
    }

    #[Test]
    public function clearsNotesWhenNotProvided(): void
    {
        $user = $this->makeUser(id: 112);
        $existing = $this->makeMeasurement(
            $user,
            id: 6,
            categoryId: null,
            values: [],
            notes: 'Old note',
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->willReturn($existing);

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Body has no 'notes' field, PUT semantics should clear it.
        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 60.0],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($existing->getNotes());
    }

    #[Test]
    public function rejectsNegativeValue(): void
    {
        $user = $this->makeUser(id: 113);
        [$em] = $this->stubForEmpty($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => -5.0],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsZeroValue(): void
    {
        $user = $this->makeUser(id: 114);
        [$em] = $this->stubForEmpty($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 0],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsValueOver500(): void
    {
        $user = $this->makeUser(id: 115);
        [$em] = $this->stubForEmpty($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 600.0],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsNonNumericValue(): void
    {
        $user = $this->makeUser(id: 116);
        [$em] = $this->stubForEmpty($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['arm' => 'sixty'],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidKeyName(): void
    {
        $user = $this->makeUser(id: 117);
        [$em] = $this->stubForEmpty($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => ['Arm Length!' => 60.0],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function acceptsEmptyValuesObject(): void
    {
        $user = $this->makeUser(id: 118);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->willReturn(null);
        $repo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', [
                'values' => [],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function categoryRouteUpsertsCategorySpecific(): void
    {
        $user = $this->makeUser(id: 119);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->with($user, 42)->willReturn(null);
        $captured = null;
        $repo->method('save')->willReturnCallback(
            function (Measurement $m) use (&$captured) { $captured = $m; }
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/category/42', [
                'values' => ['foot_length' => 26.5],
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($captured);
        self::assertSame(42, $captured->getCategoryId());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/measurements/default', ['values' => []])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array{0: EntityManagerInterface}
     */
    private function stubForEmpty(User $user): array
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $repo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Measurement::class, $repo],
            ]);
        });
        return [$em];
    }

    /**
     * @param array<string, float> $values
     */
    private function makeMeasurement(
        User $user,
        int $id,
        ?int $categoryId,
        array $values,
        ?string $notes = null,
    ): Measurement {
        $m = new Measurement($user, $values, $categoryId, $notes);
        $ref = new \ReflectionProperty(Measurement::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, $id);
        return $m;
    }
}
