<?php

namespace Tests\Unit\Support;

use App\Support\MoneyDisplay;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    public function test_formats_whole_dollar_amounts(): void
    {
        $this->assertSame('10.00 USD', MoneyDisplay::fromCents(1000));
    }

    public function test_formats_fractional_cents(): void
    {
        $this->assertSame('19.99 USD', MoneyDisplay::fromCents(1999));
    }

    public function test_formats_zero(): void
    {
        $this->assertSame('0.00 USD', MoneyDisplay::fromCents(0));
    }

    public function test_formats_null_as_an_em_dash(): void
    {
        $this->assertSame('—', MoneyDisplay::fromCents(null));
    }

    public function test_formats_negative_amounts_with_a_leading_sign(): void
    {
        $this->assertSame('-5.00 USD', MoneyDisplay::fromCents(-500));
    }

    public function test_thousands_are_comma_separated(): void
    {
        $this->assertSame('1,234,567.89 USD', MoneyDisplay::fromCents(123456789));
    }

    public function test_supports_a_non_default_currency_tag(): void
    {
        $this->assertSame('10.00 EUR', MoneyDisplay::fromCents(1000, 'EUR'));
    }
}
