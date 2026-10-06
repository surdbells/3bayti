<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Setting;

use Bayti\Api\Domain\Setting\OtpProviderSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpProviderSettings::class)]
final class OtpProviderSettingsTest extends TestCase
{
    #[Test]
    public function defaultEnablesEveryKnownProviderInDeclaredOrder(): void
    {
        $s = OtpProviderSettings::default();

        self::assertSame(['messagecentral', 'cequens'], $s->order);
        self::assertSame(['messagecentral', 'cequens'], $s->enabledInOrder());
    }

    #[Test]
    public function nullConfigFallsBackToDefault(): void
    {
        self::assertEquals(OtpProviderSettings::default(), OtpProviderSettings::fromArray(null));
    }

    #[Test]
    public function honoursStoredOrderAndEnabledFlags(): void
    {
        $s = OtpProviderSettings::fromArray([
            'order' => ['cequens', 'messagecentral'],
            'enabled' => ['cequens' => true, 'messagecentral' => false],
        ]);

        self::assertSame(['cequens', 'messagecentral'], $s->order);
        self::assertSame(['cequens'], $s->enabledInOrder(), 'a disabled provider is dropped from the send order');
    }

    #[Test]
    public function dropsUnknownProvidersAndAppendsMissingKnownOnes(): void
    {
        // Stored config knows only 'cequens' + a bogus key; messagecentral must
        // still be appended (last) so a newly-added provider never disappears.
        $s = OtpProviderSettings::fromArray([
            'order' => ['bogus', 'cequens'],
            'enabled' => ['cequens' => true],
        ]);

        self::assertSame(['cequens', 'messagecentral'], $s->order);
        // messagecentral had no explicit flag → defaults enabled.
        self::assertSame(['cequens', 'messagecentral'], $s->enabledInOrder());
    }

    #[Test]
    public function toArrayRoundTrips(): void
    {
        $original = OtpProviderSettings::fromArray([
            'order' => ['cequens', 'messagecentral'],
            'enabled' => ['cequens' => false, 'messagecentral' => true],
        ]);

        self::assertEquals($original, OtpProviderSettings::fromArray($original->toArray()));
    }

    #[Test]
    public function malformedShapesDegradeToDefaults(): void
    {
        // order/enabled of the wrong type must not blow up — treat as absent.
        $s = OtpProviderSettings::fromArray(['order' => 'nope', 'enabled' => 5]);

        self::assertSame(OtpProviderSettings::KNOWN, $s->order);
        self::assertSame(OtpProviderSettings::KNOWN, $s->enabledInOrder());
    }
}
