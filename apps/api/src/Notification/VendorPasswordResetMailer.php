<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Psr\Log\LoggerInterface;

/**
 * Builds + sends the "your seller password was reset by 3bayti" email that
 * follows an admin-initiated vendor password reset
 * (POST /v3/admin/vendors/{id}/reset-password).
 *
 * Two variants:
 *   - generated: carries the temporary password the system minted, so the
 *     vendor can sign in and set their own.
 *   - manual:    the admin chose the password and is expected to hand it over
 *     out-of-band; the email NEVER contains it, it only tells the vendor the
 *     reset happened (security notice) and how to recover if unexpected.
 *
 * Both variants tell the vendor they were signed out everywhere and will be
 * asked to choose a new password on next sign-in.
 *
 * Non-blocking: a mailer failure is logged and swallowed, the reset itself is
 * already committed by the time this runs. The return value lets the caller
 * surface "email not delivered" to the operator so they can follow up.
 */
final class VendorPasswordResetMailer
{
    public const TEMPLATE = 'vendor.password_reset';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $tempPassword The generated temporary password, or
     *                                  null when the admin set it manually.
     * @return bool true when the mailer accepted the message.
     */
    public function sendResetNotice(User $owner, Vendor $vendor, ?string $tempPassword): bool
    {
        $email = $owner->getEmail();
        $firstName = trim((string) $owner->getFirstName());
        $greeting = $firstName !== '' ? $firstName : 'there';
        $store = $vendor->getName();
        $loginUrl = $this->portalLoginUrl();
        $subject = 'Your 3bayti seller password has been reset';

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($tempPassword !== null) {
            $bodyText = "A 3bayti administrator has reset the password for your seller account "
                . "for \"{$store}\".\n\n"
                . "Your new login details:\n"
                . "  Email:    {$email}\n"
                . "  Password: {$tempPassword}\n\n"
                . "You'll be asked to choose a new password as soon as you sign in.";
            $bodyHtml = '<p style="font-size:15px;line-height:1.6;">A 3bayti administrator has reset the '
                . 'password for your seller account for <strong>' . $esc($store) . '</strong>.</p>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
                . 'style="border:1px solid #ecd9c4;border-radius:10px;background:#faf6f0;margin:16px 0;">'
                . '<tr><td style="padding:14px 18px;font-size:14px;color:#1c1c1e;line-height:1.9;">'
                . '<strong>Email:</strong> ' . $esc($email) . '<br>'
                . '<strong>Temporary password:</strong> '
                . '<code style="font-size:15px;background:#fff;padding:2px 6px;border-radius:4px;">'
                . $esc($tempPassword) . '</code>'
                . '</td></tr></table>'
                . '<p style="font-size:13px;color:#8a8378;line-height:1.6;">You\'ll be asked to choose a '
                . 'new password as soon as you sign in.</p>';
        } else {
            $bodyText = "A 3bayti administrator has reset the password for your seller account "
                . "for \"{$store}\". Our team will share your new temporary password with you "
                . "directly, you'll be asked to choose your own as soon as you sign in.";
            $bodyHtml = '<p style="font-size:15px;line-height:1.6;">A 3bayti administrator has reset the '
                . 'password for your seller account for <strong>' . $esc($store) . '</strong>. Our team '
                . 'will share your new temporary password with you directly, you\'ll be asked to choose '
                . 'your own as soon as you sign in.</p>';
        }

        $securityText = "For your security you've been signed out on all devices. If you didn't "
            . "expect this change, please contact 3bayti support.";
        $securityHtml = '<p style="font-size:13px;color:#8a8378;line-height:1.6;">For your security '
            . 'you\'ve been signed out on all devices. If you didn\'t expect this change, please contact '
            . '3bayti support.</p>';

        $text = "Hello {$greeting},\n\n"
            . $bodyText . "\n\n"
            . "Sign in to your seller dashboard:\n{$loginUrl}\n\n"
            . $securityText . "\n\n"
            . "The 3bayti Team";

        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;'
            . 'color:#1c1c1e;max-width:520px;">'
            . '<p style="font-size:15px;line-height:1.6;">Hello ' . $esc($greeting) . ',</p>'
            . $bodyHtml
            . '<p style="margin:18px 0;"><a href="' . $esc($loginUrl) . '" '
            . 'style="display:inline-block;background:#906952;color:#fff;text-decoration:none;'
            . 'padding:11px 22px;border-radius:8px;font-weight:600;font-size:15px;">Sign in to your dashboard</a></p>'
            . $securityHtml
            . '<p style="font-size:14px;color:#4a453e;line-height:1.6;margin-top:18px;">The 3bayti Team</p>'
            . '</div>';

        try {
            $this->mailer->send($email, $subject, $text, $html, [
                'template' => self::TEMPLATE,
                'vendor_id' => $vendor->getId(),
                'mode' => $tempPassword !== null ? 'generate' : 'manual',
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('vendor password-reset email failed (non-blocking)', [
                'vendor_id' => $vendor->getId(),
                'user_id' => $owner->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** Portal sign-in URL. Env-overridable; defaults to the live vendor portal. */
    private function portalLoginUrl(): string
    {
        $base = $_ENV['VENDOR_PORTAL_URL'] ?? $_ENV['PORTAL_URL'] ?? 'https://app.3bayti.ae';
        return rtrim((string) $base, '/') . '/login';
    }
}
