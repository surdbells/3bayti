<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Http\Errors\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parse + validate a schedule create/update payload. Handles template fill,
 * recurrence + date rules, and {{variable}} validation.
 */
final class ScheduleInput
{
    private const AUDIENCES = ['all', 'customers', 'vendors', 'admins'];

    /**
     * @param array<string, mixed> $body
     * @return array{
     *   name:?string, title:string, body:string, imageUrl:?string, deepLink:?string,
     *   data:array<string,string>|null, templateId:?int, audience:array<string,mixed>,
     *   frequency:string, timezone:string, startAt:DateTimeImmutable, endAt:?DateTimeImmutable, status:string
     * }
     */
    public static function parse(array $body, TemplateVariableResolver $variables, EntityManagerInterface $em): array
    {
        // Optional template fill (parity with compose).
        $templateId = isset($body['template_id']) && $body['template_id'] !== '' ? (int) $body['template_id'] : null;
        $template = null;
        if ($templateId !== null) {
            $template = $em->getRepository(NotificationTemplate::class)->find($templateId);
            if (!$template instanceof NotificationTemplate) {
                throw HttpException::badRequest('Template not found.');
            }
        }

        $title = trim((string) ($body['title'] ?? ($template?->getTitle() ?? '')));
        $message = trim((string) ($body['body'] ?? ($template?->getBody() ?? '')));
        if ($title === '') {
            throw HttpException::badRequest('title is required.');
        }
        if ($message === '') {
            throw HttpException::badRequest('body is required.');
        }

        $unknown = $variables->unknownVariables($title, $message);
        if ($unknown !== []) {
            throw HttpException::badRequest(
                'Unknown variable(s): ' . implode(', ', array_map(static fn (string $v): string => '{{' . $v . '}}', $unknown))
                . '. Supported: ' . implode(', ', TemplateVariableResolver::knownKeys()) . '.'
            );
        }

        $audienceType = (string) ($body['audience'] ?? 'all');
        if (!in_array($audienceType, self::AUDIENCES, true)) {
            throw HttpException::badRequest('audience must be one of: ' . implode(', ', self::AUDIENCES) . '.');
        }

        $frequency = (string) ($body['frequency'] ?? NotificationSchedule::FREQ_ONCE);
        if (!in_array($frequency, NotificationSchedule::FREQUENCIES, true)) {
            throw HttpException::badRequest('frequency must be one of: ' . implode(', ', NotificationSchedule::FREQUENCIES) . '.');
        }

        $timezone = self::validTimezone((string) ($body['timezone'] ?? 'Asia/Dubai'));

        $status = (string) ($body['status'] ?? NotificationSchedule::STATUS_SCHEDULED);
        if (!in_array($status, [NotificationSchedule::STATUS_DRAFT, NotificationSchedule::STATUS_SCHEDULED], true)) {
            throw HttpException::badRequest("status must be 'draft' or 'scheduled'.");
        }

        $startAt = self::parseDate($body['start_at'] ?? null, 'start_at', $timezone);
        if ($startAt === null) {
            throw HttpException::badRequest('start_at is required.');
        }
        // A live (non-draft) schedule can't start in the past (60s grace).
        if ($status === NotificationSchedule::STATUS_SCHEDULED) {
            $now = new DateTimeImmutable('now');
            if ($startAt < $now->modify('-60 seconds')) {
                throw HttpException::badRequest('start_at cannot be in the past.');
            }
        }

        $endAt = self::parseDate($body['end_at'] ?? null, 'end_at', $timezone);
        if ($endAt !== null && $endAt <= $startAt) {
            throw HttpException::badRequest('end_at must be after start_at.');
        }
        if ($endAt !== null && $frequency === NotificationSchedule::FREQ_ONCE) {
            // A one-off has no recurrence window.
            $endAt = null;
        }

        $imageUrl = self::nullableStr($body['image_url'] ?? null, 1000) ?? $template?->getImageUrl();
        $deepLink = self::nullableStr($body['deep_link'] ?? null, 1000) ?? $template?->getDeepLink();
        $name = self::nullableStr($body['name'] ?? null, 200);

        $data = null;
        if (isset($body['data']) && is_array($body['data'])) {
            $data = [];
            foreach ($body['data'] as $k => $v) {
                $data[(string) $k] = is_scalar($v) ? (string) $v : (string) json_encode($v);
            }
            if ($data === []) {
                $data = null;
            }
        }

        return [
            'name' => $name,
            'title' => $title,
            'body' => $message,
            'imageUrl' => $imageUrl,
            'deepLink' => $deepLink,
            'data' => $data,
            'templateId' => $templateId,
            'audience' => ['type' => $audienceType],
            'frequency' => $frequency,
            'timezone' => $timezone,
            'startAt' => $startAt,
            'endAt' => $endAt,
            'status' => $status,
        ];
    }

    private static function parseDate(mixed $v, string $field, string $tz): ?DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            // A naive `datetime-local` value ("2026-08-20T14:30") is read as the
            // schedule's timezone; an explicit offset in the string wins.
            return new DateTimeImmutable(trim($v), new DateTimeZone($tz));
        } catch (\Throwable) {
            throw HttpException::badRequest("$field is not a valid date/time.");
        }
    }

    private static function validTimezone(string $tz): string
    {
        try {
            new DateTimeZone($tz);
            return $tz;
        } catch (\Throwable) {
            return 'Asia/Dubai';
        }
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
