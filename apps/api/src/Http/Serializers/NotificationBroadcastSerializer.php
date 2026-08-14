<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationBroadcastRecipient;
use DateTimeInterface;

/**
 * Serialize notification broadcasts + their recipient rows for the admin
 * notification history / details / drill-down screens.
 *
 * `$userNames` (id => display name) is supplied by the controller so the
 * list can show "Sent By" without an N+1 lookup per row.
 */
final class NotificationBroadcastSerializer
{
    /**
     * History-row shape (summary — never touches the recipients table).
     *
     * @param array<int, string> $userNames
     * @return array<string, mixed>
     */
    public function historyShape(NotificationBroadcast $b, array $userNames = []): array
    {
        $total = $b->getRecipientsTotal();
        $sentBy = $b->getSentByUserId();

        return [
            'id' => $b->getId(),
            'title' => $b->getTitle(),
            'message_preview' => $this->preview($b->getBody()),
            'audience' => $b->getAudience(),
            'audience_label' => $this->audienceLabel($b->getAudience()),
            'status' => $b->getStatus(),
            'recipients_total' => $total,
            'sent_count' => $b->getSentCount(),
            'failed_count' => $b->getFailedCount(),
            'android_total' => $b->getAndroidTotal(),
            'ios_total' => $b->getIosTotal(),
            'delivery_rate' => $this->rate($b->getSentCount(), $total),
            'failure_rate' => $this->rate($b->getFailedCount(), $total),
            'sent_by_user_id' => $sentBy,
            'sent_by_name' => $sentBy !== null ? ($userNames[$sentBy] ?? null) : null,
            'resent_from_broadcast_id' => $b->getResentFromBroadcastId(),
            'created_at' => $b->getCreatedAt()->format(DateTimeInterface::ATOM),
            'finished_at' => $b->getFinishedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Detail shape — history summary + full message, device breakdown,
     * failure diagnostics.
     *
     * @param array<int, string> $userNames
     * @return array<string, mixed>
     */
    public function detailShape(NotificationBroadcast $b, array $userNames = []): array
    {
        return array_merge($this->historyShape($b, $userNames), [
            'body' => $b->getBody(),
            'image_url' => $b->getImageUrl(),
            'deep_link' => $b->getDeepLink(),
            'data' => $b->getData(),
            'resend_mode' => $b->getResendMode(),
            'started_at' => $b->getStartedAt()?->format(DateTimeInterface::ATOM),
            'error_sample' => $b->getErrorSample(),
            'failure_kinds' => $b->getFailureKinds(),
            'device_breakdown' => [
                'android' => [
                    'total' => $b->getAndroidTotal(),
                    'sent' => $b->getAndroidSent(),
                    'failed' => $b->getAndroidFailed(),
                ],
                'ios' => [
                    'total' => $b->getIosTotal(),
                    'sent' => $b->getIosSent(),
                    'failed' => $b->getIosFailed(),
                ],
            ],
        ]);
    }

    /**
     * @param iterable<NotificationBroadcast> $broadcasts
     * @param array<int, string> $userNames
     * @return list<array<string, mixed>>
     */
    public function historyShapeMany(iterable $broadcasts, array $userNames = []): array
    {
        $out = [];
        foreach ($broadcasts as $b) {
            $out[] = $this->historyShape($b, $userNames);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function recipientShape(NotificationBroadcastRecipient $r): array
    {
        return [
            'id' => $r->getId(),
            'user_id' => $r->getUserId(),
            'platform' => $r->getPlatform(),
            'token_suffix' => $r->getTokenSuffix(),
            'status' => $r->getStatus(),
            'error_kind' => $r->getErrorKind(),
            'error_message' => $r->getErrorMessage(),
            'sent_at' => $r->getSentAt()?->format(DateTimeInterface::ATOM),
            'created_at' => $r->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<NotificationBroadcastRecipient> $recipients
     * @return list<array<string, mixed>>
     */
    public function recipientShapeMany(iterable $recipients): array
    {
        $out = [];
        foreach ($recipients as $r) {
            $out[] = $this->recipientShape($r);
        }
        return $out;
    }

    private function preview(string $body): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        return mb_strlen($clean) > 90 ? mb_substr($clean, 0, 90) . '…' : $clean;
    }

    /** @param array<string, mixed> $audience */
    private function audienceLabel(array $audience): string
    {
        return match ((string) ($audience['type'] ?? 'all')) {
            'customers' => 'Customers',
            'vendors' => 'Vendors',
            'admins' => 'Admins',
            default => 'Everyone',
        };
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
    }
}
