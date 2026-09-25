<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\PaymentLineState;
use PHPUnit\Framework\TestCase;

class PaymentLineStateTest extends TestCase
{
    public function test_paid_line_cannot_return_to_a_non_paid_state(): void
    {
        $this->expectException(ApiException::class);
        PaymentLineState::assertCanChange('paid', 'defaulted');
    }

    public function test_unpaid_line_can_be_posted_or_defaulted(): void
    {
        self::assertNull(PaymentLineState::assertCanChange('pending', 'paid'));
        self::assertNull(PaymentLineState::assertCanChange('defaulted', 'paid'));
    }
}
