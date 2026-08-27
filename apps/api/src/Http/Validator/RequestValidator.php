<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Validator;

use Bayti\Api\Http\Errors\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Decode + validate a JSON request body into a typed DTO.
 *
 * Workflow
 * --------
 *
 *   1. Controller declares an input DTO with `#[Assert\...]` attributes
 *      describing field constraints:
 *
 *        final class LoginInput
 *        {
 *            public function __construct(
 *                #[Assert\NotBlank]
 *                #[Assert\Email]
 *                public readonly string $email = '',
 *
 *                #[Assert\NotBlank]
 *                #[Assert\Length(min: 8)]
 *                public readonly string $password = '',
 *            ) {}
 *        }
 *
 *   2. Controller calls $validator->parse($request, LoginInput::class).
 *      We:
 *        a) Decode the JSON body
 *        b) Hydrate the DTO from the decoded array (matching by property name)
 *        c) Run symfony/validator over it
 *        d) Return the populated DTO, or throw HttpException(422)
 *           with field-level error details
 *
 * What this DOES NOT do
 * ---------------------
 *   - Doesn't handle multipart/form-data (we're a JSON API)
 *   - Doesn't try to recover from missing fields by defaulting them -
 *     the DTO's own defaults handle that
 *   - Doesn't auto-trim whitespace, if a field needs that, add
 *     #[Assert\NotBlank(allowNull: false)] or do it in the DTO
 *
 * Why hand-rolled instead of using a fancier library
 * --------------------------------------------------
 * symfony/serializer + symfony/validator together is the standard
 * setup but it pulls in two more packages. This is a simple
 * "JSON object → constructor-args → DTO" mapper that fits in 50
 * lines and we control every line.
 */
final class RequestValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return T
     * @throws HttpException 400 if body isn't a JSON object;
     *                       422 if validation fails.
     */
    public function parse(ServerRequestInterface $request, string $dtoClass): object
    {
        $body = $this->decodeBody($request);
        $dto = $this->hydrate($dtoClass, $body);
        $this->validate($dto);
        return $dto;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(ServerRequestInterface $request): array
    {
        // Slim's body-parsing middleware pre-parses JSON into the
        // request's parsedBody (an array). If the middleware didn't
        // run or the body was empty/invalid, parsedBody is null.
        $parsed = $request->getParsedBody();

        if ($parsed === null) {
            // Empty body is allowed for some endpoints; treat as an
            // empty object so the DTO's defaults take effect.
            // The validator will then catch any required fields.
            return [];
        }

        if (!is_array($parsed)) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        return $parsed;
    }

    /**
     * Build a DTO from a decoded JSON body. We walk the DTO's
     * constructor parameters and pluck matching keys from the body.
     * Missing keys fall back to constructor defaults; extra keys
     * are ignored.
     *
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<string, mixed> $body
     * @return T
     */
    private function hydrate(string $dtoClass, array $body): object
    {
        $reflection = new \ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            // DTO with no constructor, just instantiate.
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $body)) {
                $args[$name] = $body[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$name] = $param->getDefaultValue();
            } else {
                // No value supplied and no default, pass null and
                // let validation catch the missing field. (Type
                // declarations like `string` would TypeError here,
                // but our DTOs have defaults on every field.)
                $args[$name] = null;
            }
        }

        try {
            return $reflection->newInstanceArgs($args);
        } catch (\TypeError $e) {
            // Field had wrong type (e.g. expected string, got int).
            // Translate to a 422 with field-level info if possible.
            throw HttpException::validation([
                '_root' => [$this->extractTypeErrorMessage($e)],
            ]);
        }
    }

    private function validate(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if ($violations->count() === 0) {
            return;
        }

        throw HttpException::validation($this->groupViolationsByField($violations));
    }

    /**
     * Group symfony validator violations by property path, so the
     * client sees:
     *   { fields: { email: ["Not a valid email."], password: ["Too short."] } }
     *
     * @return array<string, list<string>>
     */
    private function groupViolationsByField(ConstraintViolationListInterface $violations): array
    {
        $byField = [];
        foreach ($violations as $v) {
            $path = $v->getPropertyPath() !== '' ? $v->getPropertyPath() : '_root';
            $byField[$path] ??= [];
            $byField[$path][] = (string) $v->getMessage();
        }
        return $byField;
    }

    /**
     * Sanitise a TypeError message so it doesn't leak stack frames.
     * Symfony's validator messages are friendlier; this is a fallback
     * for "wrong JSON shape" cases.
     */
    private function extractTypeErrorMessage(\TypeError $e): string
    {
        // PHP TypeError messages typically include 'Argument #N (...)'.
        // Trim aggressively to avoid leaking the DTO class name.
        $msg = $e->getMessage();
        if (preg_match('/Argument #\d+ \(\$(\w+)\) must be of type (\S+)/i', $msg, $m)) {
            return "Field '{$m[1]}' must be of type {$m[2]}.";
        }
        return 'A field has the wrong type.';
    }
}
