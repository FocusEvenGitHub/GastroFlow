<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\OrderValidator;
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
}
