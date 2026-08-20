<?php
namespace App;

class Settings
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__);
    }

    public function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? null;
        return ($value !== null && $value !== '') ? $value : $default;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getLogDir(): string
    {
        return $this->basePath . '/logs';
    }

    public function getLogFile(): string
    {
        return $this->getLogDir() . '/app.log';
    }

    public function getPublicDir(): string
    {
        return $this->basePath . '/public';
    }

    public function getPublicAssetsImgDir(): string
    {
        return $this->getPublicDir() . '/assets/img';
    }

    public function getLogoPath(): string
    {
        return $this->getPublicAssetsImgDir() . '/logo.png';
    }
}