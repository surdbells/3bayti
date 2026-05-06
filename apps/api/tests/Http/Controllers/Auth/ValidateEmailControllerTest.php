<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ValidateEmailInput;
use Bayti\Api\Http\Controllers\Auth\ValidateEmailController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ValidateEmailController::class)]
#[CoversClass(ValidateEmailInput::class)]
final class ValidateEmailControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsAvailableTrueWhenEmailNotInDb(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())
            ->method('isEmailAvailable')
            ->with('newuser@example.com')
            ->willReturn(true);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $request = $this->jsonRequest('POST', '/v3/auth/validate-email', [
            'email' => 'newuser@example.com',
        ]);

        $response = $this->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame('newuser@example.com', $body['email']);
        self::assertTrue($body['available']);
    }

    #[Test]
    public function returnsAvailableFalseWhenEmailExists(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('isEmailAvailable')->willReturn(false);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-email', [
            'email' => 'taken@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->jsonBody($response)['available']);
    }

    #[Test]
    public function normalizesEmailBeforeChecking(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // Whatever we pass in, the repo should see lowercased + trimmed.
        $repo->expects(self::once())
            ->method('isEmailAvailable')
            ->with('alice@example.com')
            ->willReturn(true);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-email', [
            'email' => '  Alice@EXAMPLE.com  ',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('alice@example.com', $this->jsonBody($response)['email']);
    }

    #[Test]
    public function returns422WhenEmailMissing(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-email', []));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('email', $body['error']['details']['fields']);
    }

    #[Test]
    public function returns422WhenEmailMalformed(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-email', [
            'email' => 'not-an-email',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }
}
