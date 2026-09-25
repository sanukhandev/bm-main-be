<?php

namespace Tests\Unit\Support;

use App\Support\DecimalAmount;
use PHPUnit\Framework\TestCase;

class DecimalAmountTest extends TestCase
{
    /** @dataProvider amounts */
    public function test_decimal_amount_conversion_preserves_existing_rules(string $input, int $expected): void
    {
        self::assertSame($expected, DecimalAmount::toCents($input));
    }

    public static function amounts(): array
    {
        return [
            ['0', 0], ['0.00', 0], ['0.01', 1], ['1', 100], ['1.1', 110], ['1.10', 110],
            ['123.45', 12345], ['001.20', 120], ['10.999', 1099], ['100000000.00', 10000000000],
            ['-1.20', -80], ['', 0], ['invalid', 0],
        ];
    }
}
