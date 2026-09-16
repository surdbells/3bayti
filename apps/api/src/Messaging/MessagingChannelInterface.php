<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging;

/**
 * Outbound messaging over a conversational channel (WhatsApp today).
 *
 * The application (the WhatsApp webhook conversation handler) depends on this
 * interface, never on a concrete provider. A {@see NullMessagingChannel} stands
 * in when no provider is configured, so the container always boots and every
 * caller can guard on isEnabled() — mirroring
 * {@see \Bayti\Api\Sms\SmsSenderInterface} / the shipping provider pattern.
 * Env-gated by WHATSAPP_ENABLED in DI.
 *
 * Replies are free-form text sent inside the customer-initiated 24-hour window
 * (a reply to their inbound message), so no pre-approved template is required.
 */
interface MessagingChannelInterface
{
    /** True only for a real, configured channel. */
    public function isEnabled(): bool;

    /**
     * Send a plain-text message to a recipient (E.164 without the leading '+',
     * as WhatsApp expects, e.g. '971501234567').
     *
     * @throws MessagingException on a transport/auth error (real channel only);
     *         the null channel never throws.
     */
    public function sendText(string $to, string $text): MessageResult;
}
