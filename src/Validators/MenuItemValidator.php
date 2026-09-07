<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

/**
 * Shape validation for menu item create/update (docs/ROADMAP.md's v1.7.0
 * "Input validation" — spec 027). Mirrors OrderValidator's conventions
 * (spec 022). Business rules (e.g. category_name must reference an existing
 * category) stay in MenuRepository — this only checks input shape.
 */
class MenuItemValidator
{
    // "A reasonable limit," not a roadmap-specified value (same precedent as
    // OrderValidator's MAX_ITEM_QUANTITY/MAX_NOTES_LENGTH, spec 022):
    // description is TEXT in the DB, so nothing enforces a limit downstream.
    private const MAX_DESCRIPTION_LENGTH = 1000;

    private Validator $v;

    public function validateCreate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', ['name', 'price', 'category_name']);
        $this->v->rule('lengthMax', 'name', 100); // menu_items.name VARCHAR(100)
        $this->v->rule('lengthMax', 'category_name', 100); // categories.name VARCHAR(100)
        $this->v->rule('numeric', 'price');
        $this->v->rule('min', 'price', 0); // a menu item cannot cost less than free
        $this->v->rule('optional', 'description');
        $this->v->rule('lengthMax', 'description', self::MAX_DESCRIPTION_LENGTH);
        $this->v->rule('optional', 'available');
        $this->v->rule(function ($field, $value) {
            return is_bool($value);
        }, 'available')->message('{field} deve ser verdadeiro ou falso');
        return $this->v->validate();
    }

    /**
     * Caller is expected to keep rejecting a fully empty payload with its own
     * EMPTY_PAYLOAD check first (MenuController::updateItem() already does
     * this) — an empty PATCH isn't a per-field shape problem, so it's not
     * expressed as a validation rule here.
     */
    public function validateUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'name');
        $this->v->rule('lengthMax', 'name', 100);
        $this->v->rule('optional', 'category_name');
        $this->v->rule('lengthMax', 'category_name', 100);
        $this->v->rule('optional', 'price');
        $this->v->rule('numeric', 'price');
        $this->v->rule('min', 'price', 0);
        $this->v->rule('optional', 'description');
        $this->v->rule('lengthMax', 'description', self::MAX_DESCRIPTION_LENGTH);
        $this->v->rule('optional', 'available');
        $this->v->rule(function ($field, $value) {
            return is_bool($value);
        }, 'available')->message('{field} deve ser verdadeiro ou falso');
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}
