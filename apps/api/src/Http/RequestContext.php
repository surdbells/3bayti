<?php

declare(strict_types=1);

namespace Bayti\Api\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Helpers for pulling per-request context (client IP, user agent)
 * out of a PSR-7 request.
 *
 * Used by controllers + middleware that need to record audit info
 * (IP of OTP request, user-agent of refresh-token issuance, etc.).
 *
 * Why a trait
 * -----------
 * Same reasoning as Responder — these are tiny utilities that
 * belong with the consumer, not behind a service interface.
 */
trait RequestContext
{
    /**
     * Extract the client's IP address. Prefers the leftmost entry of
     * X-Forwarded-For (set by trusted reverse proxies — DigitalOcean
     * App Platform + Cloudflare); falls back to the connection's
     * remote address.
     *
     * Trust note: only the proxies we control should set XFF; if an
     * untrusted client sets it, they can spoof their IP. Production
     * deployment will be behind DO App Platform + Cloudflare which
     * both manage XFF correctly. M5 hardening will explicitly verify
     * X-Forwarded-By against a known proxy list.
     */
    protected function extractIp(ServerRequestInterface $request): ?string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }

    /**
     * Extract the User-Agent header, or null if absent. Used for
     * recording session-issuance context in user_refresh_tokens.
     */
    protected function extractUserAgent(ServerRequestInterface $request): ?string
    {
        $ua = $request->getHeaderLine('User-Agent');
        return $ua !== '' ? $ua : null;
    }
}
