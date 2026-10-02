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
     * Per-unit packaging fee used when no menu item is linked to the dining
     * option (spec 050) — the values hardcoded before that spec.
     */
    public const DEFAULT_PACKAGING_FEES = [
        'viagem_simples' => 1.0,
        'viagem_vip'     => 2.0,
    ];

    /**
     * Packaging fee for one order item, by dining option. $unitFee is the
     * price of the menu item linked to that option (spec 050); null falls
     * back to DEFAULT_PACKAGING_FEES. Non-"viagem" options are always free.
     */
    public function packagingFeeFor(string $diningOption, int $quantity, ?Money $unitFee = null): Money
    {
        if (!isset(self::DEFAULT_PACKAGING_FEES[$diningOption])) {
            return Money::zero();
        }
        $unitFee ??= Money::fromReais(self::DEFAULT_PACKAGING_FEES[$diningOption]);
        return $unitFee->multipliedBy($quantity);
    }

    /**
     * Unit price for a menu item, as an exact Money value.
     */
    public function unitPriceFor(MenuItem $menuItem): Money
    {
        return Money::fromReais($menuItem->price);
    }

    /**
     * Unit price for one build-your-own dish ("Monte Seu Prato", spec 030):
     * the dish's own base price plus each chosen add-on's unit price times
     * its quantity.
     *
     * @param iterable<array{price: Money, quantity: int}> $components
     */
    public function composedUnitPrice(Money $basePrice, iterable $components): Money
    {
        $total = $basePrice;
        foreach ($components as $component) {
            $total = $total->plus($component['price']->multipliedBy($component['quantity']));
        }
        return $total;
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
