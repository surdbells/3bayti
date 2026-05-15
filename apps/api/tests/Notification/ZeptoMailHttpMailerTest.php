<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\ZeptoMailHttpMailer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZeptoMailHttpMailer::class)]
final class ZeptoMailHttpMailerTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $capturedRequests = [];

    #[Test]
    public function constructorRejectsEmptyApiToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ZEPTOMAIL_API_TOKEN');
        new ZeptoMailHttpMailer(apiToken: '', fromEmail: 'a@b.com', fromName: '3bayti');
    }

    #[Test]
    public function constructorRejectsEmptyFromEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ZEPTOMAIL_FROM_EMAIL');
        new ZeptoMailHttpMailer(apiToken: 'tok', fromEmail: '', fromName: '3bayti');
    }

    #[Test]
    public function rejectsEmptyRecipient(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'tok', fromEmail: 'no-reply@b.com', fromName: '3bayti',
            httpClient: $this->client(new Response(201)),
        );

        try {
            $mailer->send(to: '', subject: 'x', textBody: 'x', htmlBody: 'x');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_TRANSPORT, $e->kind);
        }
    }

    #[Test]
    public function rejectsMalformedRecipient(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'tok', fromEmail: 'no-reply@b.com', fromName: '3bayti',
            httpClient: $this->client(new Response(201)),
        );

        try {
            $mailer->send(to: 'not-an-email', subject: 'x', textBody: 'x', htmlBody: 'x');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_TRANSPORT, $e->kind);
            self::assertStringContainsString('not a valid email', $e->getMessage());
        }
    }

    #[Test]
    public function happyPath201Sends(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'my-token',
            fromEmail: 'noreply@3bayti.ae',
            fromName: '3bayti',
            httpClient: $this->client(new Response(201, [], '{"data":[{"code":"EM_104","additional_info":"","message":"Email request received"}],"message":"OK"}')),
        );

        $mailer->send(
            to: 'customer@example.com',
            subject: 'Test',
            textBody: 'plain',
            htmlBody: '<p>html</p>',
        );

        // Verify request shape
        self::assertCount(1, $this->capturedRequests);
        $req = $this->capturedRequests[0];
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('api.zeptomail.com', $req['uri']);
        self::assertSame('Zoho-enczapikey my-token', $req['headers']['Authorization'][0]);

        $body = json_decode($req['body'], true);
        self::assertSame('noreply@3bayti.ae', $body['from']['address']);
        self::assertSame('3bayti', $body['from']['name']);
        self::assertSame('customer@example.com', $body['to'][0]['email_address']['address']);
        self::assertSame('Test', $body['subject']);
        self::assertSame('plain', $body['textbody']);
        self::assertSame('<p>html</p>', $body['htmlbody']);
    }

    #[Test]
    public function http401ReturnsAuthKind(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'bad', fromEmail: 'a@b.com', fromName: 'x',
            httpClient: $this->client(new Response(401, [], '{"error":"invalid token"}')),
        );

        try {
            $mailer->send(to: 'a@b.com', subject: 's', textBody: 't', htmlBody: 'h');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_AUTH, $e->kind);
        }
    }

    #[Test]
    public function http429ReturnsRateLimitKind(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'tok', fromEmail: 'a@b.com', fromName: 'x',
            httpClient: $this->client(new Response(429, [], '{"error":"rate limited"}')),
        );

        try {
            $mailer->send(to: 'a@b.com', subject: 's', textBody: 't', htmlBody: 'h');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_RATE_LIMIT, $e->kind);
        }
    }

    #[Test]
    public function http500ReturnsTransportKind(): void
    {
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'tok', fromEmail: 'a@b.com', fromName: 'x',
            httpClient: $this->client(new Response(500, [], '{"error":"server error"}')),
        );

        try {
            $mailer->send(to: 'a@b.com', subject: 's', textBody: 't', htmlBody: 'h');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_TRANSPORT, $e->kind);
        }
    }

    #[Test]
    public function networkErrorReturnsNetworkKind(): void
    {
        $exception = new ConnectException(
            'connection refused',
            new Request('POST', 'https://api.zeptomail.com/v1.1/email'),
        );
        $mailer = new ZeptoMailHttpMailer(
            apiToken: 'tok', fromEmail: 'a@b.com', fromName: 'x',
            httpClient: $this->client($exception),
        );

        try {
            $mailer->send(to: 'a@b.com', subject: 's', textBody: 't', htmlBody: 'h');
            $this->fail('Expected MailerException');
        } catch (MailerException $e) {
            self::assertSame(MailerException::KIND_NETWORK, $e->kind);
            self::assertNotNull($e->getPrevious());
        }
    }

    // ===== Helpers =====

    /**
     * Build a Guzzle Client backed by a MockHandler. Captures every
     * request that goes through into $this->capturedRequests so tests
     * can assert on the wire shape.
     *
     * @param Response|\Throwable $responseOrException
     */
    private function client($responseOrException): Client
    {
        $mock = new MockHandler([$responseOrException]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::tap(function ($req) {
            $this->capturedRequests[] = [
                'method' => $req->getMethod(),
                'uri' => (string) $req->getUri(),
                'headers' => $req->getHeaders(),
                'body' => (string) $req->getBody(),
            ];
            $req->getBody()->rewind();
        }));
        return new Client(['handler' => $stack]);
    }
}
