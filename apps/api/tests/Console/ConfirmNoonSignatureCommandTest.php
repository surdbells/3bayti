<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\ConfirmNoonSignatureCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfirmNoonSignatureCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/noon-confirm-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs [body, signature]
     */
    private function writeCapture(string $file, array $pairs): void
    {
        $lines = [];
        foreach ($pairs as [$body, $sig]) {
            $lines[] = json_encode([
                'received_at' => '2026-06-18T00:00:00+00:00',
                'content_type' => 'application/json',
                'signature_header' => $sig,
                'body_length' => strlen($body),
                'raw_body' => $body,
            ]);
        }
        file_put_contents($this->dir . '/' . $file, implode("\n", $lines) . "\n");
    }

    public function testConfirmsHmacSha256Hex(): void
    {
        $secret = 'shh-secret';
        $b1 = '{"order":{"id":"1"}}';
        $b2 = '{"order":{"id":"2"}}';
        $this->writeCapture('noon-webhook-2026-06-18.jsonl', [
            [$b1, hash_hmac('sha256', $b1, $secret)],
            [$b2, hash_hmac('sha256', $b2, $secret)],
        ]);

        $tester = new CommandTester(new ConfirmNoonSignatureCommand($this->dir));
        $exit = $tester->execute(['--secret' => $secret]);

        self::assertSame(0, $exit);
        $out = $tester->getDisplay();
        self::assertStringContainsString('hmac-sha256-hex', $out);
        self::assertStringContainsString('Confirmed', $out);
        self::assertStringContainsString('NOON_VERIFY_SIGNATURE=true', $out);
    }

    public function testConfirmsSha512Base64AndUsesEnvSecret(): void
    {
        // Secret supplied via constructor (mirrors the NOON_WEBHOOK_SECRET
        // default in bin/console) rather than the --secret option.
        $secret = 'another-secret';
        $b1 = '{"x":1}';
        $b2 = '{"x":2}';
        $this->writeCapture('caps.jsonl', [
            [$b1, base64_encode(hash_hmac('sha512', $b1, $secret, true))],
            [$b2, base64_encode(hash_hmac('sha512', $b2, $secret, true))],
        ]);

        $tester = new CommandTester(new ConfirmNoonSignatureCommand($this->dir, $secret));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $out = $tester->getDisplay();
        self::assertStringContainsString('hmac-sha512-base64', $out);
        // Non-default algo → tool steers to a verifier swap, not the flag.
        self::assertStringContainsString('config/di.php', $out);
    }

    public function testNoMatchReturnsFailure(): void
    {
        // Signatures computed with a DIFFERENT secret than the one we pass.
        $b1 = '{"order":{"id":"1"}}';
        $this->writeCapture('caps.jsonl', [
            [$b1, hash_hmac('sha256', $b1, 'real-secret')],
        ]);

        $tester = new CommandTester(new ConfirmNoonSignatureCommand($this->dir, 'wrong-secret'));
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No candidate scheme matched', $tester->getDisplay());
    }

    public function testSkipsRecordsWithoutSignature(): void
    {
        $secret = 'shh';
        $b1 = '{"order":{"id":"1"}}';
        // One signed pair + one record with a null signature (must be skipped,
        // not break confirmation).
        $this->writeCapture('caps.jsonl', [
            [$b1, hash_hmac('sha256', $b1, $secret)],
            ['{"order":{"id":"2"}}', ''],
        ]);

        $tester = new CommandTester(new ConfirmNoonSignatureCommand($this->dir, $secret));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $out = $tester->getDisplay();
        self::assertStringContainsString('skipped', $out);
        self::assertStringContainsString('Confirmed', $out);
    }

    public function testFailsWhenNoSecret(): void
    {
        $tester = new CommandTester(new ConfirmNoonSignatureCommand($this->dir, null));
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No secret', $tester->getDisplay());
    }

    public function testFailsWhenNoCaptureFiles(): void
    {
        $tester = new CommandTester(
            new ConfirmNoonSignatureCommand($this->dir . '/does-not-exist', 'secret')
        );
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No capture files', $tester->getDisplay());
    }
}
