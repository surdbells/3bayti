<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ValidatePhoneInput;
use Bayti\Api\Http\Controllers\Auth\ValidatePhoneController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ValidatePhoneController::class)]
#[CoversClass(ValidatePhoneInput::class)]
final class ValidatePhoneControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsAvailableTrueWhenPhoneNotInDb(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())
            ->method('isPhoneAvailable')
            ->with('+971501234567')
            ->willReturn(true);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-phone', [
            'phone' => '+971501234567',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->jsonBody($response)['available']);
    }

    #[Test]
    public function returnsAvailableFalseWhenPhoneTaken(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('isPhoneAvailable')->willReturn(false);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-phone', [
            'phone' => '+971501234567',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->jsonBody($response)['available']);
    }

    #[Test]
    public function returns422WhenPhoneMissingPlusPrefix(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-phone', [
            'phone' => '971501234567', // no leading +
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns422WhenPhoneTooShort(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/validate-phone', [
            'phone' => '+12345',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }
}
