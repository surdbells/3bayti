<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Http\Errors\HttpException;

/**
 * Shared parse + validation for template create/update payloads (name,
 * title, body required; image/deep-link optional; unknown {{variables}}
 * rejected with the offending names).
 */
final class TemplateInput
{
    /**
     * @param array<string, mixed> $body
     * @return array{0:string,1:string,2:string,3:?string,4:?string} [name,title,body,imageUrl,deepLink]
     */
    public static function parse(array $body, TemplateVariableResolver $variables): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $message = trim((string) ($body['body'] ?? ''));

        if ($name === '') {
            throw HttpException::badRequest('name is required.');
        }
        if ($title === '') {
            throw HttpException::badRequest('title is required.');
        }
        if ($message === '') {
            throw HttpException::badRequest('body is required.');
        }

        $unknown = $variables->unknownVariables($title, $message);
        if ($unknown !== []) {
            throw HttpException::badRequest(
                'Unknown variable(s): ' . implode(', ', array_map(
                    static fn (string $v): string => '{{' . $v . '}}',
                    $unknown,
                )) . '. Supported: ' . implode(', ', TemplateVariableResolver::knownKeys()) . '.'
            );
        }

        $imageUrl = self::nullableStr($body['image_url'] ?? null, 1000);
        $deepLink = self::nullableStr($body['deep_link'] ?? null, 1000);

        return [$name, $title, $message, $imageUrl, $deepLink];
    }

    private static function nullableStr(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
