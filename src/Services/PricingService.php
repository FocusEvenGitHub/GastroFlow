<?php

declare(strict_types=1);

namespace App\Services;

use App\InvalidDiscountException;
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
     * packaging fee, minus its discount (spec 058; zero when omitted).
     */
    public function lineTotal(Money $unitPrice, int $quantity, Money $packagingFee, ?Money $discount = null): Money
    {
        $total = $unitPrice->multipliedBy($quantity)->plus($packagingFee);
        return $discount === null ? $total : $total->minus($discount);
    }

    // ── Item discount (spec 058) ──
    //
    // A discount belongs to one order line and applies to its product value
    // (unit price × quantity, build-your-own add-ons included) — never to the
    // packaging fee. Values are handled as integer hundredths: a percent of
    // "12.5" is 1250 basis points, a fixed "5" is 500 cents.

    public const DISCOUNT_TYPES = ['percent', 'fixed'];

    /** Product value of one line: unit price × quantity, packaging excluded. */
    public function lineProductValue(Money $unitPrice, int $quantity): Money
    {
        return $unitPrice->multipliedBy($quantity);
    }

    /**
     * A decimal value as typed ("20", "12.5", 5.25) in integer hundredths.
     * Shape is already guaranteed by OrderValidator; this only converts.
     */
    public static function toHundredths(int|float|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * Discount amount for a line, from the stored rule, without any limit
     * check: percent of the product value (half-up), or the fixed amount.
     */
    public function lineDiscount(string $type, int $valueHundredths, Money $lineProductValue): Money
    {
        return $type === 'percent'
            ? $lineProductValue->percentOf($valueHundredths)
            : Money::fromCents($valueHundredths);
    }

    /** Largest discount the admin's maximum (in hundredths of a percent) allows on a line. */
    public function discountCap(Money $lineProductValue, int $maxPercentHundredths): Money
    {
        return $lineProductValue->percentOf($maxPercentHundredths);
    }

    /**
     * A discount chosen by a person (cashier, or kitchen in "Editar Pedido"):
     * computes its amount and enforces every rule — discounts enabled, a line
     * with value, never above the line's product value, never above the
     * admin's maximum. Throws InvalidDiscountException with the user-facing
     * message otherwise.
     */
    public function chosenDiscount(string $type, int $valueHundredths, Money $lineProductValue, int $maxPercentHundredths): Money
    {
        if ($maxPercentHundredths <= 0) {
            throw new InvalidDiscountException('O desconto está desativado.');
        }
        if ($lineProductValue->isZero()) {
            throw new InvalidDiscountException('Este item não tem valor para dar desconto.');
        }
        $amount = $this->lineDiscount($type, $valueHundredths, $lineProductValue);
        if ($amount->isZero()) {
            throw new InvalidDiscountException('Informe um valor maior que zero.');
        }
        if ($amount->isGreaterThan($lineProductValue)) {
            throw new InvalidDiscountException(
                'O desconto não pode ser maior que o valor do item (R$ ' . $lineProductValue->format() . ').'
            );
        }
        $cap = $this->discountCap($lineProductValue, $maxPercentHundredths);
        $overCap = $type === 'percent'
            ? $valueHundredths > $maxPercentHundredths
            : $amount->isGreaterThan($cap);
        if ($overCap) {
            throw new InvalidDiscountException(sprintf(
                'O desconto máximo permitido é de %s%% (R$ %s neste item).',
                self::formatHundredths($maxPercentHundredths),
                $cap->format()
            ));
        }
        return $amount;
    }

    /**
     * Automatic recalculation after the line changed without anyone touching
     * its discount (kitchen quantity change, spec 052 re-pricing): percent is
     * reapplied to the new value; fixed is kept but clamped to the new value.
     * The admin's maximum governs a person's choice, so it is not re-enforced.
     */
    public function adjustedDiscount(string $type, int $valueHundredths, Money $lineProductValue): Money
    {
        return Money::min($this->lineDiscount($type, $valueHundredths, $lineProductValue), $lineProductValue);
    }

    /** "30", "12,5", "33,33" — hundredths formatted for a message, trailing zeros dropped. */
    public static function formatHundredths(int $hundredths): string
    {
        $text = number_format($hundredths / 100, 2, ',', '');
        return rtrim(rtrim($text, '0'), ',');
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
