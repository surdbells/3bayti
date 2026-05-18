<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Notification service for cart-scoped emails (M3.2.X.11-E).
 *
 * Parallel to OrderNotificationService. Q-NotificationServiceShape
 * = A locked: separate service rather than adding cart methods to
 * the order service. Keeps the existing surface stable and lets
 * the cart-side opt-out gating + cart-scoped notification_log
 * shape live in their own class.
 *
 * Public API
 * ==========
 *   cartAbandoned(Cart \$cart): void
 *
 * Eligibility checks (in order):
 *   1. \$cart->getUser() must not be null
 *   2. user must have a non-empty email
 *   3. user must NOT have marketing_emails_opt_out = TRUE
 *
 * Each check writes a STATUS_SKIPPED row to notification_logs
 * with the cart_id populated, so the X.11-C CartAbandonmentFinder
 * sees it on the next run and excludes the cart from re-evaluation.
 * This is the 'persistent suppression marker' pattern — opt-out
 * is honored AND we don't waste resources re-checking the same
 * cart forever.
 *
 * Opt-out gating is the only material difference from the order
 * service: order notifications are TRANSACTIONAL (required for
 * service function under PDPL) and IGNORE the opt-out flag;
 * cart abandonment is MARKETING and HONORS it.
 *
 * The CartEmailTemplateRenderer needs an unsubscribe_url in its
 * \$extra map; we generate it here via UnsubscribeTokenIssuer.
 */
/**
 * @internal Marked non-final to allow PHPUnit class doubles in
 *           the console-command test (X.11-F). The service is
 *           still designed for single-instance use; no extension
 *           hooks. Same posture as OrderTimelineBuilder /
 *           CartAbandonmentFinder.
 */
class CartNotificationService
{
    private const SKIP_NO_USER = 'no_user';
    private const SKIP_NO_EMAIL = 'no_email';
    private const SKIP_OPTED_OUT = 'marketing_opted_out';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CartEmailTemplateRenderer $renderer,
        private readonly UnsubscribeTokenIssuer $tokenIssuer,
        private readonly LoggerInterface $logger,
        private readonly string $appBaseUrl,
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    public function cartAbandoned(Cart $cart): void
    {
        $template = EmailTemplate::CART_ABANDONED_CUSTOMER;

        // Check 1: user exists
        $user = $cart->getUser();
        if ($user === null) {
            $this->logger->info('cart_notification.skipped', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'reason' => self::SKIP_NO_USER,
            ]);
            $this->persistSkipped($cart, $template, '', self::SKIP_NO_USER);
            return;
        }

        // Check 2: email present
        $email = $user->getEmail();
        if ($email === '') {
            $this->logger->info('cart_notification.skipped', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'reason' => self::SKIP_NO_EMAIL,
            ]);
            $this->persistSkipped($cart, $template, '', self::SKIP_NO_EMAIL);
            return;
        }

        // Check 3: opt-out
        if ($user->isMarketingEmailsOptedOut()) {
            $this->logger->info('cart_notification.skipped', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'reason' => self::SKIP_OPTED_OUT,
            ]);
            $this->persistSkipped($cart, $template, $email, self::SKIP_OPTED_OUT);
            return;
        }

        // All checks passed — render + send
        $this->safeSend($cart, $user, $template, $email);
    }

    private function safeSend(
        Cart $cart,
        User $user,
        EmailTemplate $template,
        string $email,
    ): void {
        // Cart owner's locale is the only signal needed — unlike
        // OrderNotificationService which has to dispatch to customer
        // + vendor + admin recipients on the same order.
        $locale = $this->normalizeLocale($user->getLocale());

        $unsubscribeUrl = $this->buildUnsubscribeUrl($user);
        $resumeUrl = $this->buildResumeUrl($cart);

        $extra = [
            'unsubscribe_url' => $unsubscribeUrl,
            'resume_url' => $resumeUrl,
        ];

        try {
            $rendered = $this->renderer->render($template, $cart, $extra, $locale);
            $this->mailer->send(
                to: $email,
                subject: $rendered->subject,
                textBody: $rendered->textBody,
                htmlBody: $rendered->htmlBody,
                context: [
                    'template' => $template->value,
                    'cart_id' => $cart->getId(),
                    'user_id' => $user->getId(),
                    'locale' => $locale,
                ],
            );
            $this->logger->info('cart_notification.sent', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'recipient' => $email,
            ]);
            $this->persistSent($cart, $template, $email);
        } catch (MailerException $e) {
            $this->logger->error('cart_notification.send_failed', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'recipient' => $email,
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);
            $this->persistFailed($cart, $template, $email, $e->kind, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('cart_notification.unexpected_error', [
                'cart_id' => $cart->getId(),
                'template' => $template->value,
                'recipient' => $email,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $this->persistFailed($cart, $template, $email, $e::class, $e->getMessage());
        }
    }

    private function buildUnsubscribeUrl(User $user): string
    {
        $userId = $user->getId();
        if ($userId === null) {
            // Should not happen in practice (user is always persisted
            // before a cart can be created), but defensive
            return rtrim($this->appBaseUrl, '/') . '/v3/notifications/unsubscribe';
        }
        $token = $this->tokenIssuer->issue($userId);
        return rtrim($this->appBaseUrl, '/')
            . '/v3/notifications/unsubscribe?token=' . urlencode($token);
    }

    private function buildResumeUrl(Cart $cart): string
    {
        $cartId = $cart->getId();
        if ($cartId === null) {
            return rtrim($this->appBaseUrl, '/') . '/cart';
        }
        return rtrim($this->appBaseUrl, '/') . '/cart?cart_id=' . $cartId;
    }

    /**
     * Normalize a BCP-47 locale tag to the short form the renderer
     * expects ('en' or 'ar'). Falls back to 'en' for anything else.
     * Mirrors LocaleResolver's normalization but kept local since
     * we have a single recipient (the cart owner).
     */
    private function normalizeLocale(string $locale): string
    {
        $short = strtolower(substr($locale, 0, 2));
        return $short === User::LOCALE_AR ? User::LOCALE_AR : User::LOCALE_EN;
    }

    // -----------------------------------------------------------------
    // notification_logs persistence
    // -----------------------------------------------------------------

    private function persistSent(Cart $cart, EmailTemplate $template, string $recipient): void
    {
        $this->safePersist(NotificationLog::sent(
            orderId: null,
            template: $template->value,
            recipient: $recipient,
            cartId: $cart->getId(),
        ));
    }

    private function persistFailed(
        Cart $cart,
        EmailTemplate $template,
        string $recipient,
        string $errorKind,
        string $errorMessage,
    ): void {
        $this->safePersist(NotificationLog::failed(
            orderId: null,
            template: $template->value,
            recipient: $recipient,
            errorKind: $errorKind,
            errorMessage: $errorMessage,
            cartId: $cart->getId(),
        ));
    }

    private function persistSkipped(
        Cart $cart,
        EmailTemplate $template,
        string $recipient,
        string $reason,
    ): void {
        $this->safePersist(NotificationLog::skipped(
            orderId: null,
            template: $template->value,
            recipient: $recipient,
            reason: $reason,
            cartId: $cart->getId(),
        ));
    }

    private function safePersist(NotificationLog $log): void
    {
        if ($this->em === null) {
            return;
        }
        try {
            $repo = $this->em->getRepository(NotificationLog::class);
            if (!$repo instanceof NotificationLogRepository) {
                return;
            }
            $repo->save($log);
        } catch (\Throwable $e) {
            $this->logger->error('cart_notification.log_persist_failed', [
                'template' => $log->getTemplate(),
                'recipient' => $log->getRecipient(),
                'cart_id' => $log->getCartId(),
                'status' => $log->getStatus(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
