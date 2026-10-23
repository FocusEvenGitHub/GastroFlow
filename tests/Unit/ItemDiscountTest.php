<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\InvalidDiscountException;
use App\Money;
use App\Services\PricingService;
use App\Validators\OrderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Item discount rules (spec 058): pure PricingService/Money arithmetic and the
 * OrderValidator shape check. No DB.
 */
class ItemDiscountTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        $this->pricing = new PricingService();
    }

    private function cents(int $cents): Money
    {
        return Money::fromCents($cents);
    }

    public function testPercentOfRoundsHalfUpInIntegerCents(): void
    {
        // AC4: 33.33% of R$ 15,00 = 499.95 cents → 500.
        $this->assertSame(500, $this->cents(1500)->percentOf(3333)->getCents());
        $this->assertSame(800, $this->cents(4000)->percentOf(2000)->getCents());
        $this->assertSame(1, $this->cents(5)->percentOf(1000)->getCents()); // 0.5 → 1
        $this->assertSame(0, $this->cents(4)->percentOf(1000)->getCents()); // 0.4 → 0
    }

    public function testMoneyHelpers(): void
    {
        $this->assertSame(300, $this->cents(500)->minus($this->cents(200))->getCents());
        $this->assertTrue($this->cents(2)->isGreaterThan($this->cents(1)));
        $this->assertFalse($this->cents(1)->isGreaterThan($this->cents(1)));
        $this->assertSame(1, Money::min($this->cents(1), $this->cents(2))->getCents());
        $this->assertTrue(Money::zero()->isZero());
    }

    public function testPercentDiscountOnTheWholeLine(): void
    {
        // AC1: 2× R$ 20,00 at 20% → R$ 8,00.
        $line = $this->pricing->lineProductValue(Money::fromReais(20), 2);
        $this->assertSame(800, $this->pricing->chosenDiscount('percent', 2000, $line, 10000)->getCents());
    }

    public function testFixedDiscountAppliesToTheLineNotPerUnit(): void
    {
        // AC2: 2× R$ 20,00 minus R$ 5,00 → line R$ 35,00, not R$ 30,00.
        $line = $this->pricing->lineProductValue(Money::fromReais(20), 2);
        $discount = $this->pricing->chosenDiscount('fixed', 500, $line, 10000);
        $total = $this->pricing->lineTotal(Money::fromReais(20), 2, Money::zero(), $discount);
        $this->assertSame(3500, $total->getCents());
    }

    public function testPackagingIsNeverDiscounted(): void
    {
        // AC3: 1× R$ 20,00 VIP (R$ 2,00 packaging) at 100% → R$ 2,00.
        $line = $this->pricing->lineProductValue(Money::fromReais(20), 1);
        $discount = $this->pricing->chosenDiscount('percent', 10000, $line, 10000);
        $total = $this->pricing->lineTotal(Money::fromReais(20), 1, Money::fromReais(2), $discount);
        $this->assertSame(2000, $discount->getCents());
        $this->assertSame(200, $total->getCents());
    }

    public function testLineTotalWithoutDiscountIsUnchanged(): void
    {
        $this->assertSame(
            $this->pricing->lineTotal(Money::fromReais(20), 2, Money::fromReais(2))->getCents(),
            $this->pricing->lineTotal(Money::fromReais(20), 2, Money::fromReais(2), null)->getCents()
        );
    }

    public function testFixedAboveTheItemValueIsRejected(): void
    {
        $this->expectException(InvalidDiscountException::class);
        $this->expectExceptionMessage('O desconto não pode ser maior que o valor do item (R$ 40,00).');
        $this->pricing->chosenDiscount('fixed', 4001, $this->cents(4000), 10000);
    }

    public function testFixedEqualToTheItemValueIsAccepted(): void
    {
        $this->assertSame(4000, $this->pricing->chosenDiscount('fixed', 4000, $this->cents(4000), 10000)->getCents());
    }

    public function testPercentAboveTheAdminMaximumIsRejected(): void
    {
        $this->expectException(InvalidDiscountException::class);
        $this->expectExceptionMessage('O desconto máximo permitido é de 30% (R$ 12,00 neste item).');
        $this->pricing->chosenDiscount('percent', 3100, $this->cents(4000), 3000);
    }

    public function testFixedAboveTheAdminMaximumIsRejectedAndAtItIsAccepted(): void
    {
        // AC6: maximum 30% of R$ 40,00 = R$ 12,00.
        $this->assertSame(1200, $this->pricing->chosenDiscount('fixed', 1200, $this->cents(4000), 3000)->getCents());
        $this->expectException(InvalidDiscountException::class);
        $this->pricing->chosenDiscount('fixed', 1201, $this->cents(4000), 3000);
    }

    public function testMaximumZeroDisablesDiscounts(): void
    {
        $this->expectException(InvalidDiscountException::class);
        $this->expectExceptionMessage('O desconto está desativado.');
        $this->pricing->chosenDiscount('percent', 1000, $this->cents(4000), 0);
    }

    public function testZeroValueLineCannotBeDiscounted(): void
    {
        $this->expectException(InvalidDiscountException::class);
        $this->pricing->chosenDiscount('percent', 1000, Money::zero(), 10000);
    }

    public function testDiscountThatRoundsToZeroIsRejected(): void
    {
        $this->expectException(InvalidDiscountException::class);
        $this->expectExceptionMessage('Informe um valor maior que zero.');
        $this->pricing->chosenDiscount('percent', 1, $this->cents(100), 10000); // 0.01% of R$ 1,00
    }

    public function testAdjustedDiscountReappliesPercentAndClampsFixed(): void
    {
        // AC9: quantity 2 → 1 on R$ 20,00.
        $this->assertSame(400, $this->pricing->adjustedDiscount('percent', 2000, $this->cents(2000))->getCents());
        $this->assertSame(2000, $this->pricing->adjustedDiscount('fixed', 3000, $this->cents(2000))->getCents());
        $this->assertSame(500, $this->pricing->adjustedDiscount('fixed', 500, $this->cents(2000))->getCents());
    }

    public function testFormatHundredths(): void
    {
        $this->assertSame('30', PricingService::formatHundredths(3000));
        $this->assertSame('12,5', PricingService::formatHundredths(1250));
        $this->assertSame('33,33', PricingService::formatHundredths(3333));
    }

    public function testToHundredths(): void
    {
        $this->assertSame(2000, PricingService::toHundredths(20));
        $this->assertSame(1250, PricingService::toHundredths('12.5'));
        $this->assertSame(3333, PricingService::toHundredths(33.33));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function discountShapes(): array
    {
        return [
            'percent int'          => [['type' => 'percent', 'value' => 20], true],
            'percent float'        => [['type' => 'percent', 'value' => 12.5], true],
            'percent string'       => [['type' => 'percent', 'value' => '33.33'], true],
            'percent 100'          => [['type' => 'percent', 'value' => 100], true],
            'fixed'                => [['type' => 'fixed', 'value' => 5], true],
            'fixed cents'          => [['type' => 'fixed', 'value' => '0.01'], true],
            'unknown type'         => [['type' => 'half', 'value' => 5], false],
            'negative'             => [['type' => 'percent', 'value' => -5], false],
            'zero'                 => [['type' => 'percent', 'value' => 0], false],
            'exponent'             => [['type' => 'percent', 'value' => '1e1'], false],
            'three decimals'       => [['type' => 'fixed', 'value' => 1.005], false],
            'comma'                => [['type' => 'fixed', 'value' => '1,50'], false],
            'percent above 100'    => [['type' => 'percent', 'value' => 100.01], false],
            'fixed too large'      => [['type' => 'fixed', 'value' => 100000], false],
            'array value'          => [['type' => 'fixed', 'value' => [5]], false],
            'missing value'        => [['type' => 'fixed'], false],
            'not an object'        => ['percent', false],
        ];
    }

    #[DataProvider('discountShapes')]
    public function testDiscountShape(mixed $discount, bool $valid): void
    {
        $this->assertSame($valid, OrderValidator::validDiscountShape($discount));
    }

    public function testOrderCreationAcceptsAndRejectsItemDiscountShapes(): void
    {
        $validator = new OrderValidator();
        $this->assertTrue($validator->validateOrderData([
            'items' => [['id' => 1, 'quantity' => 2, 'discount' => ['type' => 'percent', 'value' => 20]]],
        ]));
        $this->assertFalse($validator->validateOrderData([
            'items' => [['id' => 1, 'quantity' => 2, 'discount' => ['type' => 'percent', 'value' => -1]]],
        ]));
    }

    public function testKitchenAddItemRejectsADiscount(): void
    {
        $validator = new OrderValidator();
        $this->assertFalse($validator->validateOrderItemAdd([
            'menu_item_id' => 1, 'discount' => ['type' => 'percent', 'value' => 10],
        ]));
    }

    public function testKitchenUpdateAcceptsDiscountObjectOrNull(): void
    {
        $validator = new OrderValidator();
        $this->assertTrue($validator->validateOrderItemUpdate(['discount' => ['type' => 'fixed', 'value' => 5]]));
        $this->assertTrue($validator->validateOrderItemUpdate(['discount' => null]));
        $this->assertFalse($validator->validateOrderItemUpdate(['discount' => ['type' => 'fixed', 'value' => 0]]));
    }
}
