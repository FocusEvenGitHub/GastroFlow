<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\IngredientValidator;
use PHPUnit\Framework\TestCase;

class IngredientValidatorTest extends TestCase
{
    public function testValidCreatePayloadPasses(): void
    {
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => 'Cebola',
            'unit' => 'g',
            'category' => 'vegetable',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithoutCategoryPasses(): void
    {
        // category is nullable in the DB (ingredients.category VARCHAR(50) NULL).
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => 'Cebola',
            'unit' => 'g',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithMissingRequiredFieldsFails(): void
    {
        $validator = new IngredientValidator();

        $this->assertFalse($validator->validateCreate([]));
        $this->assertFalse($validator->validateCreate(['name' => 'Cebola']));
        $this->assertFalse($validator->validateCreate(['unit' => 'g']));
    }

    public function testCreateWithNameOverLimitFails(): void
    {
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => str_repeat('a', 101),
            'unit' => 'g',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithUnitOverLimitFails(): void
    {
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => 'Cebola',
            'unit' => str_repeat('a', 21),
        ]);

        $this->assertFalse($result);
    }

    public function testCreateWithUnitAtLimitPasses(): void
    {
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => 'Cebola',
            'unit' => str_repeat('a', 20),
        ]);

        $this->assertTrue($result);
    }

    public function testCreateWithCategoryOverLimitFails(): void
    {
        $validator = new IngredientValidator();

        $result = $validator->validateCreate([
            'name' => 'Cebola',
            'unit' => 'g',
            'category' => str_repeat('a', 51),
        ]);

        $this->assertFalse($result);
    }

    public function testUpdateWithPartialFieldsPasses(): void
    {
        $validator = new IngredientValidator();

        $this->assertTrue($validator->validateUpdate(['name' => 'Cebola Roxa']));
    }

    public function testUpdateWithUnitOverLimitFails(): void
    {
        $validator = new IngredientValidator();

        $this->assertFalse($validator->validateUpdate(['unit' => str_repeat('a', 21)]));
    }
}
