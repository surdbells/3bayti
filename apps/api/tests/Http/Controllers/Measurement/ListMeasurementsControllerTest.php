<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Measurement;

use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Measurement\ListMeasurementsController;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListMeasurementsController::class)]
#[CoversClass(MeasurementSerializer::class)]
final class ListMeasurementsControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithList(): void
    {
        $user = $this->makeUser(id: 90);
        $defaultM = $this->makeMeasurement($user, id: 1, categoryId: null, values: ['arm' => 60.0, 'bust' => 92.0]);
        $catM = $this->makeMeasurement($user, id: 2, categoryId: 42, values: ['foot_length' => 26.5]);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findAllForUser')->with($user)->willReturn([$defaultM, $catM]);

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
            $this->jsonRequest('GET', '/v3/me/measurements', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['measurements']);
        self::assertNull($body['measurements'][0]['category_id']);
        self::assertSame(60.0, $body['measurements'][0]['values']['arm']);
        self::assertSame(42, $body['measurements'][1]['category_id']);
    }

    #[Test]
    public function returns200WithEmptyListWhenNone(): void
    {
        $user = $this->makeUser(id: 91);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findAllForUser')->willReturn([]);

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
            $this->jsonRequest('GET', '/v3/me/measurements', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->jsonBody($response)['measurements']);
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/measurements'));
        self::assertSame(401, $response->getStatusCode());
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
