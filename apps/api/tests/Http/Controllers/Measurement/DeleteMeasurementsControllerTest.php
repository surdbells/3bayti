<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Measurement;

use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Measurement\DeleteMeasurementsController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DeleteMeasurementsController::class)]
final class DeleteMeasurementsControllerTest extends HttpTestCase
{
    #[Test]
    public function returns204AndDeletesDefault(): void
    {
        $user = $this->makeUser(id: 130);
        $m = new Measurement($user, ['arm' => 60.0], null);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->with($user, null)->willReturn($m);
        $repo->expects(self::once())->method('remove')->with($m);

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
            $this->jsonRequest('DELETE', '/v3/me/measurements/default', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns204WhenNothingToDelete(): void
    {
        $user = $this->makeUser(id: 131);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->willReturn(null);
        $repo->expects(self::never())->method('remove');

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
            $this->jsonRequest('DELETE', '/v3/me/measurements/default', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deletesCategorySpecific(): void
    {
        $user = $this->makeUser(id: 132);
        $m = new Measurement($user, ['foot' => 26.0], 42);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $repo = $this->createMock(MeasurementRepository::class);
        $repo->method('findForUserAndCategory')->with($user, 42)->willReturn($m);
        $repo->expects(self::once())->method('remove')->with($m);

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
            $this->jsonRequest('DELETE', '/v3/me/measurements/category/42', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle($this->jsonRequest('DELETE', '/v3/me/measurements/default'));
        self::assertSame(401, $response->getStatusCode());
    }
}
