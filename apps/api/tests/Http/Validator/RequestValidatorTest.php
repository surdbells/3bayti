<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Validator;

use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Validator\RequestValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

#[CoversClass(RequestValidator::class)]
#[CoversClass(HttpException::class)]
final class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $symfonyValidator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $this->validator = new RequestValidator($symfonyValidator);
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function parsesValidJsonBodyIntoDto(): void
    {
        $request = $this->makeRequest(['email' => 'alice@example.com', 'password' => 'p4ssword!']);
        $dto = $this->validator->parse($request, RequestValidatorTestLoginDto::class);

        self::assertInstanceOf(RequestValidatorTestLoginDto::class, $dto);
        self::assertSame('alice@example.com', $dto->email);
        self::assertSame('p4ssword!', $dto->password);
    }

    #[Test]
    public function ignoresExtraFieldsInBody(): void
    {
        $request = $this->makeRequest([
            'email' => 'a@b.com',
            'password' => 'p4ssword!',
            'totallyExtra' => 'whatever',
        ]);

        $dto = $this->validator->parse($request, RequestValidatorTestLoginDto::class);
        self::assertSame('a@b.com', $dto->email);
    }

    // -------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------

    #[Test]
    public function throws422WhenRequiredFieldMissing(): void
    {
        $request = $this->makeRequest(['email' => 'a@b.com']); // missing password

        try {
            $this->validator->parse($request, RequestValidatorTestLoginDto::class);
            self::fail('Should have thrown');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(ErrorCodes::VALIDATION_FAILED, $e->errorCode);
            self::assertArrayHasKey('fields', $e->details);
            self::assertArrayHasKey('password', $e->details['fields']);
        }
    }

    #[Test]
    public function throws422OnEmailFormatViolation(): void
    {
        $request = $this->makeRequest(['email' => 'not-an-email', 'password' => 'p4ssword!']);

        try {
            $this->validator->parse($request, RequestValidatorTestLoginDto::class);
            self::fail('Should have thrown');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('email', $e->details['fields']);
        }
    }

    #[Test]
    public function aggregatesMultipleFieldErrors(): void
    {
        $request = $this->makeRequest(['email' => '', 'password' => '']);

        try {
            $this->validator->parse($request, RequestValidatorTestLoginDto::class);
            self::fail('Should have thrown');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('email', $e->details['fields']);
            self::assertArrayHasKey('password', $e->details['fields']);
        }
    }

    // -------------------------------------------------------------------
    // Bad request shapes
    // -------------------------------------------------------------------

    #[Test]
    public function emptyBodyTreatedAsEmptyObject(): void
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/whatever')
            ->withHeader('Content-Type', 'application/json');
        // No parsed body set; ParsedBody is null.

        try {
            $this->validator->parse($request, RequestValidatorTestLoginDto::class);
            self::fail('Should have thrown 422 (required fields blank)');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status, 'Empty body → all fields default to "" → fail NotBlank');
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/whatever')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
    }
}

/**
 * Test-only DTO used to drive RequestValidator. Lives in the same file
 * because it's only meaningful for these tests.
 */
final class RequestValidatorTestLoginDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public readonly string $password = '',
    ) {
    }
}
