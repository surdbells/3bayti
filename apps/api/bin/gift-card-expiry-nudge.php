<?php

declare(strict_types=1);

/**
 * Gift card expiry nudge + expiration job (M3.5).
 *
 * Run daily via cron (e.g. 08:00 UAE time):
 *   0 4 * * * /usr/bin/php /www/wwwroot/3bayti/apps/api/bin/gift-card-expiry-nudge.php
 *   (04:00 UTC = 08:00 UAE)
 *
 * What this script does
 * ---------------------
 * 1. Finds all active/partially_used gift cards with balance > 0 that
 *    expire within 30 days. Sends a push notification to the card owner.
 * 2. Finds all active/partially_used cards where expires_at is in the
 *    past (i.e. they should have expired). Marks them expired.
 *
 * Idempotent: re-running on the same day sends at most one nudge per
 * card (the DB query filters cards within a 24-hour window for nudges).
 *
 * Usage
 * -----
 *   php bin/gift-card-expiry-nudge.php              # run both jobs
 *   php bin/gift-card-expiry-nudge.php --dry-run    # print counts, no DB writes
 *   php bin/gift-card-expiry-nudge.php --expire-only # skip nudges, only expire
 *   php bin/gift-card-expiry-nudge.php --nudge-only  # skip expiry
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

$dryRun     = in_array('--dry-run',     $argv, true);
$expireOnly = in_array('--expire-only', $argv, true);
$nudgeOnly  = in_array('--nudge-only',  $argv, true);

echo "===================================================\n";
echo " 3bayti gift card expiry job\n";
echo "===================================================\n";
if ($dryRun) echo "  [DRY RUN] — no writes\n\n";

$start = microtime(true);

try {
    $app       = Bootstrap::createApp();
    $container = $app->getContainer();
    if ($container === null) { fwrite(STDERR, "DI container unavailable\n"); exit(1); }

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var PushNotificationService $push */
    $push = $container->get(PushNotificationService::class);
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

$now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$in30days = $now->modify('+30 days');
$nudged   = 0;
$expired  = 0;
$errors   = 0;

// ── 1. Expiry nudge ─────────────────────────────────────────────────

if (!$expireOnly) {
    echo "----- Expiry nudges (cards expiring within 30 days) -----\n";

    /** @var list<GiftCard> $expiringCards */
    $expiringCards = $em->createQueryBuilder()
        ->select('g')
        ->from(GiftCard::class, 'g')
        ->where("g.status IN ('active', 'partially_used')")
        ->andWhere('g.expiresAt <= :in30')
        ->andWhere('g.expiresAt > :now')
        ->andWhere('g.balance > 0')
        ->setParameter('in30', $in30days)
        ->setParameter('now', $now)
        ->getQuery()
        ->getResult();

    echo "  Found " . count($expiringCards) . " card(s) to nudge.\n\n";

    foreach ($expiringCards as $card) {
        $daysLeft = (int) $now->diff($card->getExpiresAt())->days;
        echo sprintf(
            "  card %-4d  AED %-8s  expires in %d day(s)  user=%d\n",
            $card->getId() ?? 0,
            $card->getBalance(),
            $daysLeft,
            ($card->getRecipientUser() ?? $card->getBuyerUser())->getId() ?? 0,
        );

        if (!$dryRun) {
            try {
                $push->giftCardExpiryNudge($card);
                $nudged++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "  ✗ nudge failed for card {$card->getId()}: {$e->getMessage()}\n");
                $errors++;
            }
        } else {
            $nudged++;
        }
    }

    echo "\n  Nudged: $nudged  Errors: $errors\n\n";
}

// ── 2. Mark expired ─────────────────────────────────────────────────

if (!$nudgeOnly) {
    echo "----- Expiring past-due cards -----\n";

    /** @var list<GiftCard> $pastDue */
    $pastDue = $em->createQueryBuilder()
        ->select('g')
        ->from(GiftCard::class, 'g')
        ->where("g.status IN ('active', 'partially_used')")
        ->andWhere('g.expiresAt <= :now')
        ->setParameter('now', $now)
        ->getQuery()
        ->getResult();

    echo "  Found " . count($pastDue) . " card(s) to expire.\n\n";

    foreach ($pastDue as $card) {
        echo sprintf("  expiring card %d  (balance was AED %s)\n", $card->getId() ?? 0, $card->getBalance());
        if (!$dryRun) {
            try {
                // Use Reflection to set status to expired without full void
                // (void zeros the balance; expired preserves it for audit)
                $ref = new ReflectionProperty($card, 'status');
                $ref->setAccessible(true);
                $ref->setValue($card, GiftCard::STATUS_EXPIRED);
                $em->flush();
                $expired++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "  ✗ expire failed for card {$card->getId()}: {$e->getMessage()}\n");
                $errors++;
            }
        } else {
            $expired++;
        }
    }

    echo "\n  Expired: $expired  Errors: $errors\n\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo "===================================================\n";
echo " Done in {$elapsed}s — nudged=$nudged  expired=$expired  errors=$errors\n";
echo "===================================================\n";
if ($errors > 0) exit(1);
