<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\OrderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderValidatorTest extends TestCase
{
    public function testValidPayloadPasses(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'order_number' => '5',
            'items' => [
                ['id' => 1, 'quantity' => 2],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testCustomerNameOverLimitIsRejectedOnOrderCreation(): void
    {
        // Regression: validateOrderData() dropped this rule entirely during
        // spec 022's rewrite (code review) — customer_name is still present
        // in validateOrderUpdate() the whole time, which is what caught the
        // asymmetry.
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'customer_name' => str_repeat('a', 101),
            'items' => [['id' => 1, 'quantity' => 1]],
        ]);

        $this->assertFalse($result);
    }

    public function testCustomerNameAtLimitIsAcceptedOnOrderCreation(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'customer_name' => str_repeat('a', 100),
            'items' => [['id' => 1, 'quantity' => 1]],
        ]);

        $this->assertTrue($result);
    }

    public function testMissingOrderNumberIsAccepted(): void
    {
        // order_number is optional (spec 019): omitting it lets the server
        // auto-assign the next number for today under a lock.
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'items' => [
                ['id' => 1, 'quantity' => 2],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testMissingItemsIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'order_number' => '5',
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }

    public function testItemMissingIdOrQuantityIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'order_number' => '5',
            'items' => [
                ['id' => 1],
            ],
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }

    public function testInvalidDiningOptionIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'order_number' => '5',
            'items' => [
                ['id' => 1, 'quantity' => 2, 'dining_option' => 'invalid_option'],
            ],
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }

    public function testEmptyItemsArrayIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData(['items' => []]);

        $this->assertFalse($result);
    }

    #[DataProvider('invalidQuantities')]
    public function testInvalidQuantityIsRejectedOnOrderCreation($quantity): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'items' => [['id' => 1, 'quantity' => $quantity]],
        ]);

        $this->assertFalse($result);
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero'          => [0],
            'negative'      => [-1],
            'non-integer'   => [2.5],
            'above-max'     => [51],
        ];
    }

    public function testNotesOverLimitIsRejectedOnOrderCreation(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'items' => [['id' => 1, 'quantity' => 1, 'notes' => str_repeat('a', 501)]],
        ]);

        $this->assertFalse($result);
    }

    public function testNotesAtLimitIsAcceptedOnOrderCreation(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'items' => [['id' => 1, 'quantity' => 1, 'notes' => str_repeat('a', 500)]],
        ]);

        $this->assertTrue($result);
    }

    public function testOrderItemAddRejectsInvalidDiningOption(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderItemAdd([
            'menu_item_id' => 1,
            'dining_option' => 'mesa',
        ]);

        $this->assertFalse($result);
    }

    public function testOrderItemAddRejectsQuantityAboveMax(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderItemAdd([
            'menu_item_id' => 1,
            'quantity' => 51,
        ]);

        $this->assertFalse($result);
    }

    public function testOrderItemAddRejectsNotesOverLimit(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderItemAdd([
            'menu_item_id' => 1,
            'notes' => str_repeat('a', 501),
        ]);

        $this->assertFalse($result);
    }

    public function testOrderItemUpdateRejectsQuantityAboveMax(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderItemUpdate(['quantity' => 51]);

        $this->assertFalse($result);
    }

    public function testOrderItemUpdateRejectsNotesOverLimit(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderItemUpdate(['notes' => str_repeat('a', 501)]);

        $this->assertFalse($result);
    }
}
