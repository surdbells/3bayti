<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Chat\ModerationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModerationService::class)]
#[CoversClass(\Bayti\Api\Domain\Chat\ModerationResult::class)]
final class ModerationServiceTest extends TestCase
{
    private ModerationService $svc;

    protected function setUp(): void
    {
        $this->svc = new ModerationService();
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function piiCases(): iterable
    {
        yield 'uae mobile'        => ['Call me on +971 50 123 4567', ModerationService::FLAG_PHONE];
        yield 'bare long number'  => ['my line is 0501234567 ok', ModerationService::FLAG_PHONE];
        yield 'us number'         => ['ring (415) 555-1234', ModerationService::FLAG_PHONE];
        yield 'arabic phone word' => ['رقم 0501234567', ModerationService::FLAG_PHONE];
        yield 'email'             => ['reach me at seller@gmail.com', ModerationService::FLAG_EMAIL];
        yield 'email at-spaced'   => ['seller [at] gmail.com', ModerationService::FLAG_EMAIL];
        yield 'instagram'         => ['follow my insta @cool.shop99', ModerationService::FLAG_SOCIAL];
        yield 'whatsapp'          => ['whatsapp 0521234567', ModerationService::FLAG_SOCIAL];
        yield 'address'           => ['ship to 12 Baker Street', ModerationService::FLAG_ADDRESS];
        yield 'po box'            => ['use PO Box 4456', ModerationService::FLAG_ADDRESS];
        yield 'arabic street'     => ['العنوان شارع_الحمراء', ModerationService::FLAG_ADDRESS];
    }

    #[Test]
    #[DataProvider('piiCases')]
    public function detectsPii(string $content, string $expectedFlag): void
    {
        $result = $this->svc->check($content);
        self::assertTrue($result->isFlagged, "Expected PII in: {$content}");
        self::assertContains($expectedFlag, $result->flagTypes);
    }

    /** @return iterable<string, array{0:string}> */
    public static function cleanCases(): iterable
    {
        yield 'plain question' => ['Is the abaya ready for delivery this week?'];
        yield 'thanks'         => ['Thank you, the measurements look correct.'];
        yield 'empty'          => [''];
        yield 'small number'   => ['I ordered 2 pieces in size M'];
    }

    #[Test]
    #[DataProvider('cleanCases')]
    public function passesCleanContent(string $content): void
    {
        self::assertFalse($this->svc->check($content)->isFlagged, "Should be clean: {$content}");
    }

    #[Test]
    public function redactMasksMatches(): void
    {
        $content = 'email me seller@gmail.com or call 0501234567';
        $result = $this->svc->check($content);
        $redacted = $this->svc->redact($content, $result);

        self::assertStringNotContainsString('seller@gmail.com', $redacted);
        self::assertStringNotContainsString('0501234567', $redacted);
        self::assertStringContainsString('•••', $redacted);
    }

    #[Test]
    public function flagTypeStringJoinsCategories(): void
    {
        $result = $this->svc->check('seller@gmail.com and @my_handle');
        self::assertNotNull($result->flagTypeString());
        self::assertStringContainsString('email', $result->flagTypeString());
    }
}
