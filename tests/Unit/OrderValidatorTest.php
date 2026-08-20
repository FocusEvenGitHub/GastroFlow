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
            'table' => '5',
            'items' => [
                ['id' => 1, 'quantity' => 2],
            ],
        ]);

        $this->assertTrue($result);
    }

    public function testMissingTableIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'items' => [
                ['id' => 1, 'quantity' => 2],
            ],
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }

    public function testMissingItemsIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'table' => '5',
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }

    public function testItemMissingIdOrQuantityIsRejected(): void
    {
        $validator = new OrderValidator();

        $result = $validator->validateOrderData([
            'table' => '5',
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
            'table' => '5',
            'items' => [
                ['id' => 1, 'quantity' => 2, 'dining_option' => 'invalid_option'],
            ],
        ]);

        $this->assertFalse($result);
        $this->assertNotEmpty($validator->errors());
    }
}
