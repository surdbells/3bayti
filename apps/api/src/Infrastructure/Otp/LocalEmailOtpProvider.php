<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Local email-OTP provider, generates, persists, and emails a
 * 6-digit code WITHOUT delegating to any CPaaS.
 *
 * Why local (vs. MessageCentral for SMS)
 * --------------------------------------
 * MessageCentral's CPaaS handles SMS code generation + verification
 * on their side; we never see the SMS code. For email there's no such
 * delegated verification surface, so we own the whole lifecycle:
 *
 *   send():
 *     1. Generate a cryptographically-random 6-digit code via
 *        random_int (NOT rand/mt_rand, those are predictable).
 *     2. Hash it with password_hash() and store the hash as
 *        OtpAttempt.code_hash. The PLAINTEXT IS NEVER PERSISTED.
 *     3. Mint a local verification_id ('em-' + 16 random bytes hex)
 *        so it's distinguishable from MessageCentral's ids and
 *        collision-free.
 *     4. Persist the OtpAttempt (channel=email, email set, expires
 *        +5 min, attempts=0).
 *     5. Email the plaintext code via MailerInterface.
 *     6. Return the verification_id.
 *
 * Verification is NOT done here, it lives in OtpService::verify,
 * which loads the row, checks expiry/consumed/attempt-cap, and uses
 * password_verify() (constant-time) against code_hash. Keeping verify
 * in the service matches the SMS path's shape (service orchestrates,
 * provider is the delivery channel) and keeps the attempt-cap +
 * consumed-at transitions in one place.
 *
 * Security properties
 * -------------------
 *   - Plaintext code: generated, emailed, then discarded. Only the
 *     password_hash() lands in the DB.
 *   - random_int(): CSPRNG-backed; an attacker can't predict the next
 *     code from observed ones.
 *   - Expiry + attempt-cap + consumed-at: enforced at verify time
 *     (OtpService), so a leaked DB row can't be brute-forced offline
 *     beyond the bcrypt cost, and online guessing is bounded to 5
 *     tries before the row burns.
 */
final class LocalEmailOtpProvider
{
    /** OTP lifetime, mirrors the SMS TTL (5 minutes). */
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generate + persist + email a fresh email-OTP.
     *
     * @param string  $email     Recipient address.
     * @param string  $purpose   One of OtpAttempt::ALL_PURPOSES.
     * @param string  $phone     Phone to record on the audit row (the
     *                           user's registered phone, the table's
     *                           `phone` column is NOT NULL). Pass '' if
     *                           genuinely unknown.
     * @param User|null $user        Bound user when known.
     * @param string|null $requestedIp Client IP for audit.
     *
     * @return string The locally-minted verification_id.
     *
     * @throws MailerException When the email transport fails (the OTP
     *                         row is still persisted; the caller can
     *                         surface a provider error or let the user
     *                         resend).
     */
    public function send(
        string $email,
        string $purpose,
        string $phone = '',
        ?User $user = null,
        ?string $requestedIp = null,
    ): string {
        // CSPRNG-backed 6-digit code, zero-padded so '000123' stays 6
        // chars. random_int draws from the OS CSPRNG.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $verificationId = 'em-' . bin2hex(random_bytes(16));
        $expiresAt = (new DateTimeImmutable())->modify('+' . self::TTL_SECONDS . ' seconds');

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $phone,
            purpose: $purpose,
            expiresAt: $expiresAt,
            user: $user,
            requestedIp: $requestedIp,
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash($code, PASSWORD_BCRYPT),
            email: $email,
        );

        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $repo->save($attempt);

        // Render minimal text + HTML bodies inline. We deliberately
        // keep this self-contained (no template-renderer dependency)
        // because the OTP email is a single short sentence, the
        // order/cart renderers exist for rich multi-section emails.
        [$subject, $textBody, $htmlBody] = $this->renderBodies($code);

        $this->mailer->send(
            to: $email,
            subject: $subject,
            textBody: $textBody,
            htmlBody: $htmlBody,
            context: [
                'template' => 'auth.otp',
                'purpose' => $purpose,
                'verification_id' => $verificationId,
                // NEVER include $code in the log context.
            ],
        );

        $this->logger->info('Email OTP sent', [
            'verification_id' => $verificationId,
            'email' => $email,
            'purpose' => $purpose,
        ]);

        return $verificationId;
    }

    /**
     * Render the OTP email's subject + text + html.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function renderBodies(string $code): array
    {
        $subject = 'Your 3bayti verification code';

        $textBody = "Your 3bayti verification code is {$code}.\n\n"
            . "It expires in 5 minutes. If you didn't request this, you can ignore this email.";

        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $htmlBody = <<<HTML
            <div style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; color: #1a1a1a;">
                <p>Your 3bayti verification code is:</p>
                <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px; margin: 16px 0;">{$safeCode}</p>
                <p style="color: #666; font-size: 14px;">It expires in 5 minutes. If you didn't request this, you can ignore this email.</p>
            </div>
            HTML;

        return [$subject, $textBody, $htmlBody];
    }
}
