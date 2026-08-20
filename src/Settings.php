<?php

declare(strict_types=1);

namespace App;

class Settings
{
    public function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? null;
        return ($value !== null && $value !== '') ? $value : $default;
    }
}