<?php
namespace App;

class Settings
{
    public function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? $default;
    }
}