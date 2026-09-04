<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

class OrderValidator
{
    private Validator $v;

    public function validateOrderData(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', 'table_number');
        $this->v->rule('lengthMax', 'table_number', 50);
        $this->v->rule('required', 'items');
        $this->v->rule('array', 'items');
        // customer_name is optional; if present, must be a string
        $this->v->rule('optional', 'customer_name');
        $this->v->rule('lengthMax', 'customer_name', 100);
        // each item must have id, quantity
        $this->v->rule(function($field, $value, $params, $fields) {
            if (!is_array($value)) return false;
            foreach ($value as $item) {
                if (!isset($item['id'], $item['quantity'])) return false;
                if (!is_numeric($item['id']) || !is_numeric($item['quantity'])) return false;
                // dining_option is optional; if present must be valid
                if (isset($item['dining_option'])) {
                    if (!in_array($item['dining_option'], ['local', 'viagem_simples', 'viagem_vip'], true)) {
                        return false;
                    }
                }
            }
            return true;
        }, 'items');
        return $this->v->validate();
    }

    public function validateOrderUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'table_number');
        $this->v->rule('lengthMax', 'table_number', 50);
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
        $this->v->rule('optional', 'notes');
        return $this->v->validate();
    }

    public function validateOrderItemUpdate(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('optional', 'quantity');
        $this->v->rule('integer', 'quantity');
        $this->v->rule('min', 'quantity', 1);
        $this->v->rule('optional', 'notes');
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}