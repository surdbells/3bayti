<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Common;

use Bayti\Api\Domain\Common\PhoneNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhoneNumber::class)]
final class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function cases(): array
    {
        return [
            // The reported bug: +971 concatenated onto a local number that kept
            // its trunk zero. The stray 0 must be dropped.
            'stray trunk zero after +971'   => ['+9710508816837', '+971508816837'],
            'clean e164 unchanged'          => ['+971508816837', '+971508816837'],
            'local with trunk zero'         => ['0508816837', '+971508816837'],
            'bare national → default dial'  => ['508816837', '+971508816837'],
            'country code without plus'      => ['971508816837', '+971508816837'],
            'double-zero intl prefix'        => ['00971508816837', '+971508816837'],
            'formatting stripped'            => ['+971 50 881 6837', '+971508816837'],
            'dashes and parens stripped'     => ['+971-(50)-881-6837', '+971508816837'],
            // Another GCC dial code is recognised, not forced to +971.
            'saudi number preserved'         => ['+966512345678', '+966512345678'],
            'saudi local with zero'          => ['966 0512345678', '+966512345678'],
            // No usable digits → null (caller keeps original / rejects).
            'empty'                          => ['', null],
            'non-numeric'                    => ['n/a', null],
            'only zeros'                     => ['+9710000000', null],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function canonicalisesToE164(string $raw, ?string $expected): void
    {
        self::assertSame($expected, PhoneNumber::toE164($raw));
    }

    #[Test]
    public function respectsAnExplicitDefaultDialForBareNationalNumbers(): void
    {
        // A bare national number with no recognisable dial code adopts the
        // caller's default (e.g. the SMS sender's configured country).
        self::assertSame('+965512345678', PhoneNumber::toE164('0512345678', '965'));
    }
}
