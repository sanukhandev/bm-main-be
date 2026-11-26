<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_decimal_amounts_convert_to_cents(string|int|float $amount, int $expected): void
    {
        $this->assertSame($expected, Money::toCents($amount));
    }

    public static function validAmounts(): array
    {
        return [[1000, 100000], ['10.5', 1050], ['33.33', 3333], ['0.01', 1], ['-5.00', -500]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_decimal_inputs_are_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents($amount);
    }

    public static function invalidAmounts(): array
    {
        return [['1e3'], ['10.999'], [' 1.00'], ['999999999999999999999999.00']];
    }

    public function test_cents_format_without_float_rounding(): void
    {
        $this->assertSame('1000000000000000.01', Money::formatCents(100000000000000001));
    }
}
