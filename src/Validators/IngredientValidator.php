<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

/**
 * Shape validation for ingredient create/update (docs/ROADMAP.md's v1.7.0
 * "Input validation" — spec 027). Mirrors OrderValidator's conventions
 * (spec 022).
 */
class IngredientValidator
{
    private Validator $v;

    public function validateCreate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', ['name', 'unit']);
        $this->v->rule('lengthMax', 'name', 100); // ingredients.name VARCHAR(100)
        $this->v->rule('lengthMax', 'unit', 20); // ingredients.unit VARCHAR(20)
        $this->v->rule('optional', 'category');
        $this->v->rule('lengthMax', 'category', 50); // ingredients.category VARCHAR(50)
        return $this->v->validate();
    }

    /**
     * Caller is expected to reject a fully empty payload itself, the same
     * way MenuController::updateItem() does — an empty PUT isn't a per-field
     * shape problem.
     */
    public function validateUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'name');
        $this->v->rule('lengthMax', 'name', 100);
        $this->v->rule('optional', 'unit');
        $this->v->rule('lengthMax', 'unit', 20);
        $this->v->rule('optional', 'category');
        $this->v->rule('lengthMax', 'category', 50);
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}
