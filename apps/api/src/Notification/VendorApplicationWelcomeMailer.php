<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\User\User;
use Psr\Log\LoggerInterface;

/**
 * Builds + sends the "your 3bayti seller account is approved" welcome email
 * that hands a freshly-approved applicant their login credentials.
 *
 * Shared by the approve flow and the "resend credentials" admin action so the
 * copy + credential handling live in one place. Entirely non-blocking, a
 * mailer failure is logged and swallowed; it must never fail the caller's
 * primary action (the account/vendor are already committed by then).
 */
final class VendorApplicationWelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A readable temporary password for an admin-provisioned seller account.
     * Excludes visually ambiguous characters (0/O, 1/l/I) so it can be typed
     * straight from the email; the holder is forced to change it on first
     * sign-in (User::requirePasswordChange()).
     */
    public function generateTempPassword(int $length = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }
        return $password;
    }

    /**
     * Welcome the approved applicant + hand them their login details.
     *
     * A freshly-provisioned user gets a temporary password to sign in with
     * (pass it as $tempPassword); an applicant who already had a 3bayti account
     * keeps their existing credentials, pass null and they're pointed at the
     * sign-in page instead (we never expose a password they already control).
     */
    public function sendApprovalWelcome(
        User $user,
        VendorApplication $application,
        ?string $tempPassword,
    ): void {
        $loginUrl = $this->portalLoginUrl();
        $firstName = $application->getFirstName();
        $store = $application->getBusinessName();
        $email = $user->getEmail();
        $subject = 'Your 3bayti seller account is approved';

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($tempPassword !== null) {
            $credsText = "Your login details:\n"
                . "  Email:    {$email}\n"
                . "  Password: {$tempPassword}\n\n"
                . "You'll be asked to set a new password the first time you sign in.";
            $credsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
                . 'style="border:1px solid #ecd9c4;border-radius:10px;background:#faf6f0;margin:16px 0;">'
                . '<tr><td style="padding:14px 18px;font-size:14px;color:#1c1c1e;line-height:1.9;">'
                . '<strong>Email:</strong> ' . $esc($email) . '<br>'
                . '<strong>Temporary password:</strong> '
                . '<code style="font-size:15px;background:#fff;padding:2px 6px;border-radius:4px;">'
                . $esc($tempPassword) . '</code>'
                . '</td></tr></table>'
                . '<p style="font-size:13px;color:#8a8378;line-height:1.6;">You\'ll be asked to set a '
                . 'new password the first time you sign in.</p>';
        } else {
            $credsText = "Sign in with your existing 3bayti account ({$email}). If you've forgotten "
                . "your password, use \"Forgot password\" on the sign-in page.";
            $credsHtml = '<p style="font-size:14px;color:#4a453e;line-height:1.6;">Sign in with your '
                . 'existing 3bayti account (<strong>' . $esc($email) . '</strong>). If you\'ve forgotten '
                . 'your password, use <em>Forgot password</em> on the sign-in page.</p>';
        }

        $text = "Hello {$firstName},\n\n"
            . "Great news — your application to sell on 3bayti has been approved, and your store "
            . "\"{$store}\" is now active.\n\n"
            . "Sign in to your seller dashboard:\n{$loginUrl}\n\n"
            . $credsText . "\n\n"
            . "Welcome aboard,\nThe 3bayti Team";

        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#1c1c1e;max-width:520px;">'
            . '<p style="font-size:15px;line-height:1.6;">Hello ' . $esc($firstName) . ',</p>'
            . '<p style="font-size:15px;line-height:1.6;">Great news — your application to sell on 3bayti '
            . 'has been approved, and your store <strong>' . $esc($store) . '</strong> is now active.</p>'
            . '<p style="margin:18px 0;"><a href="' . $esc($loginUrl) . '" '
            . 'style="display:inline-block;background:#906952;color:#fff;text-decoration:none;'
            . 'padding:11px 22px;border-radius:8px;font-weight:600;font-size:15px;">Sign in to your dashboard</a></p>'
            . $credsHtml
            . '<p style="font-size:14px;color:#4a453e;line-height:1.6;margin-top:18px;">Welcome aboard,<br>The 3bayti Team</p>'
            . '</div>';

        try {
            $this->mailer->send($email, $subject, $text, $html, [
                'template' => 'vendor_application.approved',
                'application_id' => $application->getId(),
            ]);
        } catch (MailerException | \Throwable $e) {
            $this->logger->warning('vendor-application welcome email failed (non-blocking)', [
                'application_id' => $application->getId(),
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Portal sign-in URL. Env-overridable; defaults to the live vendor portal. */
    private function portalLoginUrl(): string
    {
        $base = $_ENV['VENDOR_PORTAL_URL'] ?? $_ENV['PORTAL_URL'] ?? 'https://app.3bayti.ae';
        return rtrim((string) $base, '/') . '/login';
    }
}
