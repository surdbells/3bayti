<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Measurement;

use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Measurement\GetMeasurementsController;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetMeasurementsController::class)]
#[CoversClass(MeasurementSerializer::class)]
final class GetMeasurementsControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithDefaultMeasurements(): void
    {
        $user = $this->makeUser(id: 100);
        $m = $this->makeMeasurement($user, id: 1, categoryId: null, values: ['arm' => 60.0]);

        [$em] = $this->stubFor($user, $m, expectedCategoryId: null);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/measurements/default', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotNull($body['measurements']);
        self::assertNull($body['measurements']['category_id']);
        // JSON_PRESERVE_ZERO_FRACTION in Responder ensures 60.0 is
        // sent as `60.0` not `60`, so json_decode reads back as float.
        self::assertSame(60.0, $body['measurements']['values']['arm']);
    }

    #[Test]
    public function returns200WithNullWhenDefaultNotSet(): void
    {
        $user = $this->makeUser(id: 101);

        [$em] = $this->stubFor($user, null, expectedCategoryId: null);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/measurements/default', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->jsonBody($response)['measurements']);
    }

    #[Test]
    public function returns200WithCategoryMeasurements(): void
    {
        $user = $this->makeUser(id: 102);
        $m = $this->makeMeasurement($user, id: 2, categoryId: 42, values: ['foot' => 26.5]);

        [$em] = $this->stubFor($user, $m, expectedCategoryId: 42);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/measurements/category/42', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(42, $body['measurements']['category_id']);
    }

    #[Test]
    public function returns404ForInvalidCategoryId(): void
    {
        $user = $this->makeUser(id: 103);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        // No measurement repo expectation, bad path arg should
        // 404 before even hitting the repo.
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/measurements/category/abc', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/measurements/default'));
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array{0: EntityManagerInterface}
     */
    private function stubFor(User $user, ?Measurement $m, ?int $expectedCategoryId): array
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')
            ->with($user, $expectedCategoryId)
            ->willReturn($m);

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
    ): Measurement {
        $m = new Measurement($user, $values, $categoryId);
        $ref = new \ReflectionProperty(Measurement::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, $id);
        return $m;
    }
}
