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
     * Extract the REAL client IP address.
     *
     * Resolution order (the API runs behind Cloudflare in production):
     *   1. CF-Connecting-IP — Cloudflare sets this to the original
     *      client IP and strips any client-supplied copy at its edge, so
     *      it's the trustworthy source when we're behind Cloudflare.
     *   2. X-Forwarded-For — leftmost entry, for non-Cloudflare proxy
     *      setups (DO App Platform, local nginx). Only trustworthy when
     *      a proxy WE control set it.
     *   3. REMOTE_ADDR — the connection's remote address (direct hits).
     *
     * Each candidate is validated as a real IP (filter_var) so a spoofed
     * or garbage header value is ignored rather than treated as an IP —
     * important because the per-IP OTP rate limit keys on this value.
     *
     * Returns null when no candidate yields a valid IP; callers that
     * rate-limit by IP MUST skip the per-IP check in that case rather
     * than block (per the OTP hardening contract — never block legit
     * users when the IP can't be resolved).
     *
     * Trust note: M5 may still verify X-Forwarded-By against a known
     * proxy allow-list. CF-Connecting-IP is preferred precisely because
     * Cloudflare overwrites it at the edge (clients can't forge it
     * through CF), whereas XFF is appendable.
     */
    protected function extractIp(ServerRequestInterface $request): ?string
    {
        // 1. Cloudflare's authoritative client-IP header.
        $cf = trim($request->getHeaderLine('CF-Connecting-IP'));
        if ($cf !== '' && $this->isValidIp($cf)) {
            return $cf;
        }

        // 2. X-Forwarded-For (leftmost = original client).
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && $this->isValidIp($first)) {
                return $first;
            }
        }

        // 3. Connection remote address.
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (is_string($remote) && $this->isValidIp($remote)) {
            return $remote;
        }

        return null;
    }

    /** True if $value is a syntactically valid IPv4 or IPv6 address. */
    private function isValidIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
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
