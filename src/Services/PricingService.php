<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MenuItem;
use App\Money;

/**
 * Single place restaurant pricing policy is decided (docs/ROADMAP.md's
 * v1.7.0 "Pricing domain" — spec 026). Stateless and free of I/O so it stays
 * safely callable from inside an existing DB transaction/lock without
 * changing that transaction's semantics.
 */
class PricingService
{
    /**
     * Packaging fee for one order item, by dining option. Moved verbatim
     * from OrderRepository::packagingCostFor() — same values, same rule.
     */
    public function packagingFeeFor(string $diningOption, int $quantity): Money
    {
        return match ($diningOption) {
            'viagem_simples' => Money::fromReais(1.0)->multipliedBy($quantity),
            'viagem_vip'     => Money::fromReais(2.0)->multipliedBy($quantity),
            default          => Money::zero(),
        };
    }

    /**
     * Unit price for a menu item, as an exact Money value.
     */
    public function unitPriceFor(MenuItem $menuItem): Money
    {
        return Money::fromReais($menuItem->price);
    }

    /**
     * Line total for one order item: unit price times quantity, plus its
     * packaging fee.
     */
    public function lineTotal(Money $unitPrice, int $quantity, Money $packagingFee): Money
    {
        return $unitPrice->multipliedBy($quantity)->plus($packagingFee);
    }

    /**
     * Sum of line totals, e.g. an order's grand total. Zero for no items.
     *
     * @param iterable<Money> $lineTotals
     */
    public function orderTotal(iterable $lineTotals): Money
    {
        $total = Money::zero();
        foreach ($lineTotals as $lineTotal) {
            $total = $total->plus($lineTotal);
        }
        return $total;
    }
}
