<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\MenuItemValidator;
use PHPUnit\Framework\TestCase;

class MenuItemValidatorTest extends TestCase
{
    public function testValidCreatePayloadPasses(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato do Dia',
            'price' => 19.9,
            'category_name' => 'Pratos',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithMissingRequiredFieldsFails(): void
    {
        $validator = new MenuItemValidator();

        $this->assertFalse($validator->validateCreate([]));
    }

    public function testCreateWithNameOverLimitFails(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => str_repeat('a', 101),
            'price' => 10.0,
            'category_name' => 'Pratos',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithNameAtLimitPasses(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => str_repeat('a', 100),
            'price' => 10.0,
            'category_name' => 'Pratos',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithNegativePriceFails(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato',
            'price' => -5,
            'category_name' => 'Pratos',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithZeroPricePasses(): void
    {
        // Free items are a legitimate business case (e.g. a promotional
        // add-on) — only negative prices are rejected.
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Cortesia',
            'price' => 0,
            'category_name' => 'Pratos',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithNonNumericPriceFails(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato',
            'price' => 'gratis',
            'category_name' => 'Pratos',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithNonBooleanAvailableFails(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato',
            'price' => 10.0,
            'category_name' => 'Pratos',
            'available' => 'yes',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithBooleanAvailablePasses(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato',
            'price' => 10.0,
            'category_name' => 'Pratos',
            'available' => false,
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithDescriptionOverLimitFails(): void
    {
        $validator = new MenuItemValidator();

        $result = $validator->validateCreate([
            'name' => 'Prato',
            'price' => 10.0,
            'category_name' => 'Pratos',
            'description' => str_repeat('a', 1001),
        ]);

        $this->assertFalse($result);
    }

    public function testUpdateWithOnlyPricePasses(): void
    {
        $validator = new MenuItemValidator();

        $this->assertTrue($validator->validateUpdate(['price' => 15.0]));
    }

    public function testUpdateWithNegativePriceFails(): void
    {
        $validator = new MenuItemValidator();

        $this->assertFalse($validator->validateUpdate(['price' => -1]));
    }

    public function testUpdateWithOverLimitNameFails(): void
    {
        $validator = new MenuItemValidator();

        $this->assertFalse($validator->validateUpdate(['name' => str_repeat('a', 101)]));
    }
}
