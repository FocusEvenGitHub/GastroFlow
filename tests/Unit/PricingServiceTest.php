<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MenuItem;
use App\Money;
use App\Services\PricingService;
use PHPUnit\Framework\TestCase;

/**
 * PricingService is pure calculation (no DB, no I/O) — this test constructs
 * MenuItem instances in memory only, never touching Eloquent's connection.
 */
class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        $this->pricing = new PricingService();
    }

    public function testPackagingFeeForViagemSimples(): void
    {
        $fee = $this->pricing->packagingFeeFor('viagem_simples', 3);
        $this->assertSame(3.0, $fee->toReais());
    }

    public function testPackagingFeeForViagemVip(): void
    {
        $fee = $this->pricing->packagingFeeFor('viagem_vip', 2);
        $this->assertSame(4.0, $fee->toReais());
    }

    public function testPackagingFeeForLocalIsZero(): void
    {
        $fee = $this->pricing->packagingFeeFor('local', 5);
        $this->assertSame(0.0, $fee->toReais());
    }

    public function testUnitPriceForReadsMenuItemPrice(): void
    {
        $menuItem = new MenuItem();
        $menuItem->price = 19.9;

        $unitPrice = $this->pricing->unitPriceFor($menuItem);

        $this->assertSame(19.9, $unitPrice->toReais());
    }

    public function testLineTotalCombinesUnitPriceQuantityAndPackaging(): void
    {
        $unitPrice = Money::fromReais(10.0);
        $packagingFee = Money::fromReais(2.0);

        $lineTotal = $this->pricing->lineTotal($unitPrice, 2, $packagingFee);

        $this->assertSame(22.0, $lineTotal->toReais());
    }

    public function testComposedUnitPriceAddsEachAddOnTimesItsQuantityToTheBase(): void
    {
        // Spec 030: base 5,00 + 2 x 13,00 + 1 x 4,00 + 3 x 0,10 = 35,30 (hand-computed in cents: 500 + 2600 + 400 + 30).
        $price = $this->pricing->composedUnitPrice(Money::fromReais(5.0), [
            ['price' => Money::fromReais(13.0), 'quantity' => 2],
            ['price' => Money::fromReais(4.0), 'quantity' => 1],
            ['price' => Money::fromReais(0.1), 'quantity' => 3],
        ]);

        $this->assertSame(3530, $price->getCents());
    }

    public function testComposedUnitPriceWithNoAddOnsIsTheBase(): void
    {
        $price = $this->pricing->composedUnitPrice(Money::fromReais(20.0), []);

        $this->assertSame(2000, $price->getCents());
    }

    public function testOrderTotalSumsLineTotals(): void
    {
        $lineTotals = [
            Money::fromReais(10.0),
            Money::fromReais(19.9),
            Money::fromReais(0.1),
        ];

        $total = $this->pricing->orderTotal($lineTotals);

        $this->assertSame(30.0, $total->toReais());
    }

    public function testOrderTotalOfEmptyListIsZero(): void
    {
        $total = $this->pricing->orderTotal([]);

        $this->assertSame(0.0, $total->toReais());
    }
}
