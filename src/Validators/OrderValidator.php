<?php

namespace App\Validators;

use Valitron\Validator;

class OrderValidator
{
    private Validator $v;

    public function validateOrderData(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', 'table');
        $this->v->rule('required', 'items');
        $this->v->rule('array', 'items');
        // each item must have id, quantity
        $this->v->rule(function($field, $value, $params, $fields) {
            if (!is_array($value)) return false;
            foreach ($value as $item) {
                if (!isset($item['id'], $item['quantity'])) return false;
                if (!is_numeric($item['id']) || !is_numeric($item['quantity'])) return false;
            }
            return true;
        }, 'items');
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}