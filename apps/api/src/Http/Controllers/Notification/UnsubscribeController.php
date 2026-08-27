<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Notification;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Notification\UnsubscribeTokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /v3/notifications/unsubscribe?token=...
 *
 * Public marketing-email unsubscribe endpoint (M3.2.X.11-G). No
 * authentication required, verification is via the signed JWT
 * token in the query string.
 *
 * Q-UnsubscribeFlow = A locked: no-login flow is required for
 * PDPL compliance (right to withdraw consent must be 'as simple
 * as giving consent', UAE PDPL Article 13).
 *
 * Returns HTML (not JSON), this endpoint is hit from the user's
 * email client, not a programmatic API consumer.
 *
 * Behavior
 * ========
 *   - Valid token + user exists: set marketing_emails_opt_out=TRUE,
 *     persist, return 200 with confirmation page
 *   - Valid token + user already opted out: return 200 with the
 *     SAME confirmation page (idempotent, second click does
 *     nothing, but the user sees confirmation either way)
 *   - Valid token + user not found: return 400 with generic
 *     'invalid or expired link' page (don't leak whether the
 *     user_id was valid; the verify-then-find sequence already
 *     prevents enumeration)
 *   - Invalid token (bad signature, expired, wrong action,
 *     malformed, missing): return 400 with the SAME generic
 *     'invalid or expired link' page
 *
 * The 400 path is intentionally opaque about which failure
 * occurred. Verify() is opaque-on-failure by design (see X.11-E
 * token issuer docblock). A malicious recipient learning that
 * 'token expired' vs 'wrong action' could craft targeted retry
 * patterns; staying opaque defeats this.
 *
 * Audit
 * =====
 * Logger captures every call with the outcome. No notification_log
 * row written here (this is the inverse of a send, recording it
 * in the same table would be a category error). Future enhancement
 * could add a dedicated 'consent_log' table; deferred per
 * Q-OptOutHandling = A's minimum-viable scope.
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly UnsubscribeTokenIssuer $tokenIssuer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $_args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $_args = [],
    ): ResponseInterface {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $token = $query['token'] ?? null;

        if (!is_string($token) || $token === '') {
            $this->logger->info('unsubscribe.invalid_token', [
                'reason' => 'missing_or_empty',
            ]);
            return $this->htmlResponse(400, $this->errorPage());
        }

        $userId = $this->tokenIssuer->verify($token);
        if ($userId === null) {
            $this->logger->info('unsubscribe.invalid_token', [
                'reason' => 'verify_failed',
            ]);
            return $this->htmlResponse(400, $this->errorPage());
        }

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findById($userId);
        if ($user === null) {
            // Opaque: same 400 page as invalid-token. Don't leak
            // whether the user_id was valid.
            $this->logger->info('unsubscribe.user_not_found', [
                'user_id' => $userId,
            ]);
            return $this->htmlResponse(400, $this->errorPage());
        }

        if ($user->isMarketingEmailsOptedOut()) {
            // Idempotent: already opted out. Show confirmation
            // page so the user sees what they expected, without
            // re-persisting.
            $this->logger->info('unsubscribe.already_opted_out', [
                'user_id' => $userId,
            ]);
            return $this->htmlResponse(200, $this->successPage());
        }

        $user->setMarketingEmailsOptOut(true);
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            // Persistence failure: don't claim success. Log + show
            // a transient error message so the user can retry.
            $this->logger->error('unsubscribe.persist_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return $this->htmlResponse(500, $this->transientErrorPage());
        }

        $this->logger->info('unsubscribe.completed', [
            'user_id' => $userId,
        ]);
        return $this->htmlResponse(200, $this->successPage());
    }

    private function htmlResponse(int $status, string $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function successPage(): string
    {
        return $this->wrap(
            title: 'Unsubscribed — 3bayti',
            heading: "You've been unsubscribed.",
            body: <<<HTML
<p>You won't receive any more marketing emails from 3bayti.</p>
<p style="color: #8e8e93; font-size: 14px;">
  You'll still receive transactional emails (order confirmations,
  shipping updates, refunds, etc.) — these are required for the
  service to function.
</p>
<p style="color: #8e8e93; font-size: 14px;">
  Changed your mind? You can re-enable marketing emails any time
  from your account preferences.
</p>
HTML,
        );
    }

    private function errorPage(): string
    {
        return $this->wrap(
            title: 'Invalid link — 3bayti',
            heading: 'This unsubscribe link is invalid or has expired.',
            body: <<<HTML
<p>The link in your email is no longer valid. This can happen if:</p>
<ul>
  <li>The link is older than 30 days</li>
  <li>The link was modified or copied incorrectly</li>
  <li>The link has already been used</li>
</ul>
<p style="color: #8e8e93; font-size: 14px;">
  You can manage your email preferences from your account
  settings instead.
</p>
HTML,
        );
    }

    private function transientErrorPage(): string
    {
        return $this->wrap(
            title: 'Try again — 3bayti',
            heading: 'Something went wrong.',
            body: <<<HTML
<p>We couldn't complete your unsubscribe right now. Please try
the link again in a few minutes, or update your preferences
from your account settings.</p>
HTML,
        );
    }

    private function wrap(string $title, string $heading, string $body): string
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $headingEsc = htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleEsc}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 600px; margin: 64px auto; padding: 24px; color: #1c1c1e;">
<div style="border-bottom: 2px solid #B9935A; padding-bottom: 12px; margin-bottom: 24px;">
  <h2 style="margin: 0; color: #B9935A;">3bayti</h2>
</div>
<h1 style="font-size: 22px;">{$headingEsc}</h1>
{$body}
<hr style="border: none; border-top: 1px solid #e5e5e7; margin-top: 32px;">
<p style="font-size: 12px; color: #8e8e93;">3bayti — premium UAE marketplace</p>
</body>
</html>
HTML;
    }
}
