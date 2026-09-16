<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Channels;

use Bayti\Api\Messaging\WhatsApp\MetaWhatsAppWebhookVerifier;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/channels/whatsapp/webhook
 *
 * Meta's subscription verification handshake. Meta calls this once when the
 * webhook is registered with hub.mode=subscribe, hub.verify_token=<token>,
 * hub.challenge=<nonce>; we echo the challenge VERBATIM as text/plain only when
 * the token matches WHATSAPP_VERIFY_TOKEN, else 403.
 *
 * INTENTIONALLY UNAUTHENTICATED (Meta has no 3bayti JWT). NEVER add
 * AuthMiddleware to this route.
 */
final class WhatsAppWebhookVerifyController
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly MetaWhatsAppWebhookVerifier $verifier,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        // Slim may surface Meta's dotted keys (hub.mode) as-is or underscored;
        // read both spellings defensively.
        $mode = $this->param($q, 'hub_mode', 'hub.mode');
        $token = $this->param($q, 'hub_verify_token', 'hub.verify_token');
        $challenge = $this->param($q, 'hub_challenge', 'hub.challenge');

        if ($this->verifier->verifyChallenge($mode, $token)) {
            $response = $this->responseFactory->createResponse(200);
            $response->getBody()->write($challenge);
            return $response->withHeader('Content-Type', 'text/plain');
        }

        return $this->responseFactory->createResponse(403);
    }

    /**
     * @param array<string, mixed> $q
     */
    private function param(array $q, string $a, string $b): string
    {
        $v = $q[$a] ?? $q[$b] ?? '';
        return is_string($v) ? $v : '';
    }
}
