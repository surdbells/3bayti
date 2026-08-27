<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Payment\Noon;

use Bayti\Api\Payment\Noon\NoonWebhookCaptureRecorder;
use PHPUnit\Framework\TestCase;

final class NoonWebhookCaptureRecorderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/noon-capture-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testNoOpWhenDisabled(): void
    {
        $recorder = new NoonWebhookCaptureRecorder(enabled: false, captureDir: $this->dir);
        $recorder->record('{"a":1}', 'sig-abc', 'application/json');

        self::assertFalse($recorder->isEnabled());
        self::assertFalse(is_dir($this->dir), 'disabled recorder must not create the capture dir');
    }

    public function testRecordsRawPairVerbatimWhenEnabled(): void
    {
        $recorder = new NoonWebhookCaptureRecorder(enabled: true, captureDir: $this->dir);
        $body = '{"eventId":"e1","result":{"order":{"id":"123"}}}';
        $recorder->record($body, 'sha256=deadbeef', 'application/json');

        $files = glob($this->dir . '/noon-webhook-*.jsonl') ?: [];
        self::assertCount(1, $files);

        $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);

        // Raw bytes preserved exactly, that's what the confirm tool needs.
        self::assertSame($body, $row['raw_body']);
        self::assertSame('sha256=deadbeef', $row['signature_header']);
        self::assertSame('application/json', $row['content_type']);
        self::assertSame(strlen($body), $row['body_length']);
        self::assertArrayHasKey('received_at', $row);
        // The shared secret must never be written.
        self::assertArrayNotHasKey('secret', $row);
    }

    public function testAppendsMultipleRecords(): void
    {
        $recorder = new NoonWebhookCaptureRecorder(enabled: true, captureDir: $this->dir);
        $recorder->record('{"n":1}', 'sig1', 'application/json');
        $recorder->record('{"n":2}', 'sig2', 'application/json');

        $files = glob($this->dir . '/noon-webhook-*.jsonl') ?: [];
        self::assertCount(1, $files);
        $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);
    }

    public function testRecordsNullSignatureWithoutError(): void
    {
        $recorder = new NoonWebhookCaptureRecorder(enabled: true, captureDir: $this->dir);
        $recorder->record('{"n":1}', null, null);

        $files = glob($this->dir . '/noon-webhook-*.jsonl') ?: [];
        self::assertCount(1, $files);
        $row = json_decode(file_get_contents($files[0]), true);
        self::assertNull($row['signature_header']);
        self::assertNull($row['content_type']);
    }
}
