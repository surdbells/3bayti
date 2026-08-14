<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushMessage;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes a NotificationBroadcast: resolves its recipient set (an audience
 * query, or the failed/all recipients of a source broadcast for a resend),
 * sends each via FCM, and records a per-device result.
 *
 * Scale + memory
 * --------------
 * The audience is streamed in keyset-paginated batches — the whole
 * recipient list is never loaded at once. Recipient result rows are written
 * with DBAL (not the ORM UnitOfWork), so a million-recipient send doesn't
 * accumulate entities in memory. Only the single broadcast entity is managed
 * (for its denormalised counters), flushed once per batch.
 *
 * Delivery honesty: a send that FCM accepts is recorded 'sent'; a rejection
 * is 'failed' with the PushException kind. Dead tokens (UNREGISTERED) are
 * deactivated so they leave future audiences. Partial success is normal —
 * the broadcast finishes 'partially_delivered', never all-or-nothing.
 *
 * The broadcast is expected to already be in 'processing' (claimed by the
 * dispatcher, or set inline before calling this).
 */
final class BroadcastSender
{
    private const BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushSenderInterface $pushSender,
        private readonly TemplateVariableResolver $variables,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(NotificationBroadcast $broadcast): void
    {
        $broadcastId = (int) $broadcast->getId();
        $conn = $this->em->getConnection();

        /** @var DeviceTokenRepository $deviceRepo */
        $deviceRepo = $this->em->getRepository(DeviceToken::class);
        /** @var NotificationBroadcastRecipientRepository $recipientRepo */
        $recipientRepo = $this->em->getRepository(NotificationBroadcastRecipient::class);

        $isResend     = $broadcast->getResentFromBroadcastId() !== null;
        $onlyFailed   = $broadcast->getResendMode() === NotificationBroadcast::RESEND_FAILED;
        $sourceId     = (int) $broadcast->getResentFromBroadcastId();
        $audienceType = $this->audienceType($broadcast);

        // Message payload (data must be a string map; deep link travels in data).
        $data = $broadcast->getData() ?? [];
        $deepLink = $broadcast->getDeepLink();
        if (is_string($deepLink) && $deepLink !== '') {
            $data['deep_link'] = $deepLink;
        }
        $context = ['event' => 'admin.broadcast', 'broadcast_id' => (string) $broadcastId];

        // Dynamic variables ({{first_name}}, {{date}}, …). If the message has
        // none, build the PushMessage once; otherwise render it per recipient.
        $rawTitle = $broadcast->getTitle();
        $rawBody = $broadcast->getBody();
        $hasVars = $this->variables->hasVariables($rawTitle) || $this->variables->hasVariables($rawBody);
        $shared = $hasVars ? $this->variables->sharedTimeValues() : ['date' => '', 'time' => ''];
        $staticMessage = $hasVars ? null : new PushMessage($rawTitle, $rawBody, $data);

        // Seed totals so the history row shows the audience size immediately.
        $totals = $isResend
            ? $recipientRepo->countResendTargetsByPlatform($sourceId, $onlyFailed)
            : $deviceRepo->countActiveForAudienceByPlatform($audienceType);
        $broadcast->setRecipientTotals($totals['total'], $totals['android'], $totals['ios']);
        $this->em->flush();

        $afterId = 0;
        while (true) {
            $batch = $isResend
                ? $recipientRepo->findResendTargetsBatch($sourceId, $onlyFailed, $afterId, self::BATCH)
                : $deviceRepo->findActiveForAudienceBatch($audienceType, $afterId, self::BATCH);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $afterId = $row['id'];
                $platform = $row['platform'] === DeviceToken::PLATFORM_IOS
                    ? DeviceToken::PLATFORM_IOS
                    : DeviceToken::PLATFORM_ANDROID;

                $status = NotificationBroadcastRecipient::STATUS_SENT;
                $errorKind = null;
                $errorMessage = null;

                $message = $staticMessage;
                if ($message === null) {
                    $values = $this->variables->valuesFor([
                        'first_name' => $row['first_name'] ?? null,
                        'last_name' => $row['last_name'] ?? null,
                        'email' => $row['email'] ?? null,
                    ], $shared);
                    $message = new PushMessage(
                        $this->variables->render($rawTitle, $values),
                        $this->variables->render($rawBody, $values),
                        $data,
                    );
                }

                try {
                    $this->pushSender->sendToToken($row['token'], $message, $context);
                    $broadcast->recordSent($platform);
                } catch (PushException $e) {
                    $status = NotificationBroadcastRecipient::STATUS_FAILED;
                    $errorKind = $e->kind;
                    $errorMessage = $e->getMessage();
                    $broadcast->recordFailed($platform, $e->kind);
                    $broadcast->setErrorSample($e->getMessage());
                    if ($e->isTokenDead()) {
                        // Prune permanently dead tokens (DBAL — no UoW growth).
                        $conn->executeStatement(
                            'UPDATE device_tokens SET is_active = false WHERE id = :id',
                            ['id' => $row['id']],
                        );
                    }
                }

                // Write the result row via DBAL to keep the UnitOfWork small.
                // Timestamps are bound as values (DBAL binds every column as a
                // parameter, so a now() literal isn't possible here).
                $nowStr = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
                $conn->insert('notification_broadcast_recipients', [
                    'broadcast_id'    => $broadcastId,
                    'user_id'         => $row['user_id'] > 0 ? $row['user_id'] : null,
                    'device_token_id' => $row['id'],
                    'token_suffix'    => $this->tokenSuffix($row['token']),
                    'platform'        => $platform,
                    'status'          => $status,
                    'error_kind'      => $errorKind,
                    'error_message'   => $errorMessage,
                    'sent_at'         => $status === NotificationBroadcastRecipient::STATUS_SENT ? $nowStr : null,
                    'created_at'      => $nowStr,
                ]);
            }

            // Persist the broadcast's running counters after each batch.
            $this->em->flush();
        }

        $broadcast->finish();
        $this->em->flush();

        $this->logger->info('admin broadcast processed', [
            'broadcast_id' => $broadcastId,
            'recipients' => $broadcast->getRecipientsTotal(),
            'sent' => $broadcast->getSentCount(),
            'failed' => $broadcast->getFailedCount(),
            'status' => $broadcast->getStatus(),
        ]);
    }

    private function audienceType(NotificationBroadcast $b): string
    {
        $type = $b->getAudience()['type'] ?? 'all';
        return in_array($type, ['all', 'customers', 'vendors', 'admins'], true) ? (string) $type : 'all';
    }

    private function tokenSuffix(string $token): string
    {
        return strlen($token) <= 6 ? $token : '…' . substr($token, -6);
    }
}
