<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Messaging\WhatsApp;

use Bayti\Api\Messaging\WhatsApp\MetaWhatsAppWebhookVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetaWhatsAppWebhookVerifier::class)]
final class MetaWhatsAppWebhookVerifierTest extends TestCase
{
    #[Test]
    public function challengeMatchesOnlyWithTheCorrectTokenAndMode(): void
    {
        $v = new MetaWhatsAppWebhookVerifier('appsecret', 'verify-token');

        self::assertTrue($v->verifyChallenge('subscribe', 'verify-token'));
        self::assertFalse($v->verifyChallenge('subscribe', 'wrong-token'));
        self::assertFalse($v->verifyChallenge('unsubscribe', 'verify-token'));
    }

    #[Test]
    public function challengeRejectsWhenVerifyTokenIsUnset(): void
    {
        $v = new MetaWhatsAppWebhookVerifier('appsecret', null);
        self::assertFalse($v->verifyChallenge('subscribe', 'anything'));
    }

    #[Test]
    public function eventVerifiesAValidRawBodySignature(): void
    {
        $secret = 'appsecret';
        $body = '{"object":"whatsapp_business_account","entry":[]}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $v = new MetaWhatsAppWebhookVerifier($secret, 'vt');

        self::assertTrue($v->verifyEvent($body, $sig));
        // Wrong signature, empty signature, and a tampered body all fail.
        self::assertFalse($v->verifyEvent($body, 'sha256=deadbeef'));
        self::assertFalse($v->verifyEvent($body, ''));
        self::assertFalse($v->verifyEvent('{"tampered":true}', $sig));
    }

    #[Test]
    public function eventRejectsWhenAppSecretIsUnset(): void
    {
        $v = new MetaWhatsAppWebhookVerifier(null, 'vt');
        $sig = 'sha256=' . hash_hmac('sha256', 'x', 'whatever');
        self::assertFalse($v->verifyEvent('x', $sig));
    }
}
