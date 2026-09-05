<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testFromReaisConvertsToExactCents(): void
    {
        $this->assertSame(1990, Money::fromReais(19.90)->getCents());
        $this->assertSame(1990, Money::fromReais('19.90')->getCents());
        $this->assertSame(450, Money::fromReais(4.5)->getCents());
        $this->assertSame(0, Money::zero()->getCents());
    }

    public function testRepeatedAdditionIsExactUnlikeRawFloat(): void
    {
        // The canonical float trap: 0.1 + 0.1 + 0.1 !== 0.3 in binary float.
        $this->assertNotEquals(0.3, 0.1 + 0.1 + 0.1);

        $sum = Money::fromReais(0.10)
            ->plus(Money::fromReais(0.10))
            ->plus(Money::fromReais(0.10));

        $this->assertSame(30, $sum->getCents());
        $this->assertSame(0.3, $sum->toReais());
    }

    public function testMultipliedByIsExactIntegerArithmetic(): void
    {
        $this->assertSame(1990 * 3, Money::fromReais(19.90)->multipliedBy(3)->getCents());
    }

    public function testToReaisAndFormat(): void
    {
        $money = Money::fromReais(1234.5);
        $this->assertSame(1234.5, $money->toReais());
        $this->assertSame('1234,50', $money->format());
    }

    public function testNonNumericStringIsRejectedInsteadOfSilentlyZeroed(): void
    {
        // Code review fix: (float) "grátis" used to silently become 0.0.
        $this->expectException(\InvalidArgumentException::class);
        Money::fromReais('grátis');
    }
}
