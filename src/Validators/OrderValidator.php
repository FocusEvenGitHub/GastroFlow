<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

class OrderValidator
{
    // "A reasonable limit" per docs/ROADMAP.md's v1.7.0 "Order validation" —
    // generous defaults, not values the roadmap itself specifies (spec 022).
    private const MAX_ITEM_QUANTITY = 50;
    private const MAX_NOTES_LENGTH = 500;
    private const DINING_OPTIONS = ['local', 'viagem_simples', 'viagem_vip'];

    private Validator $v;

    public function validateOrderData(array $data): bool
    {
        $this->v = new Validator($data);
        // order_number is optional: omitting it lets the server auto-assign the
        // next number for today, under a lock (see OrderRepository::allocateNextNumber()).
        $this->v->rule('optional', 'order_number');
        $this->v->rule('lengthMax', 'order_number', 50);
        $this->v->rule('required', 'items');
        $this->v->rule('array', 'items');
        // Each item must have a valid id/quantity/dining_option/notes.
        // Existence/availability of the referenced menu item is checked in
        // OrderRepository::createOrder() — that's DB state, not input shape
        // (CLAUDE.md: "Validators validate input shape. Services enforce
        // business rules.") — spec 022.
        $this->v->rule(function($field, $value, $params, $fields) {
            if (!is_array($value) || count($value) === 0) return false;
            foreach ($value as $item) {
                if (!isset($item['id'], $item['quantity'])) return false;
                if (!is_numeric($item['id'])) return false;
                if (!is_numeric($item['quantity'])) return false;
                $quantity = $item['quantity'];
                if ((int) $quantity != $quantity) return false; // must be an integer value
                if ((int) $quantity < 1 || (int) $quantity > self::MAX_ITEM_QUANTITY) return false;
                if (isset($item['dining_option']) && !in_array($item['dining_option'], self::DINING_OPTIONS, true)) {
                    return false;
                }
                if (isset($item['notes']) && is_string($item['notes']) && mb_strlen($item['notes']) > self::MAX_NOTES_LENGTH) {
                    return false;
                }
            }
            return true;
        }, 'items');
        return $this->v->validate();
    }

    public function validateOrderUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'order_number');
        $this->v->rule('lengthMax', 'order_number', 50);
        $this->v->rule('optional', 'customer_name');
        $this->v->rule('lengthMax', 'customer_name', 100);
        return $this->v->validate();
    }

    public function validateOrderItemAdd(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', 'menu_item_id');
        $this->v->rule('integer', 'menu_item_id');
        $this->v->rule('optional', 'quantity');
        $this->v->rule('integer', 'quantity');
        $this->v->rule('min', 'quantity', 1);
        $this->v->rule('max', 'quantity', self::MAX_ITEM_QUANTITY);
        $this->v->rule('optional', 'dining_option');
        $this->v->rule('in', 'dining_option', self::DINING_OPTIONS);
        $this->v->rule('optional', 'notes');
        $this->v->rule('lengthMax', 'notes', self::MAX_NOTES_LENGTH);
        return $this->v->validate();
    }

    public function validateOrderItemUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'quantity');
        $this->v->rule('integer', 'quantity');
        $this->v->rule('min', 'quantity', 1);
        $this->v->rule('max', 'quantity', self::MAX_ITEM_QUANTITY);
        $this->v->rule('optional', 'notes');
        $this->v->rule('lengthMax', 'notes', self::MAX_NOTES_LENGTH);
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}