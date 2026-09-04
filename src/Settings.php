<?php

declare(strict_types=1);

namespace App;

class Settings
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__);
        date_default_timezone_set($this->getTimezone());
    }

    public function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? null;
        return ($value !== null && $value !== '') ? $value : $default;
    }

    public function getRequired(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException("$key environment variable is not set.");
        }
        return $value;
    }

    /**
     * Defaults to 'production' when unset — a deployment that forgets to set APP_ENV
     * gets the restrictive behavior, never the permissive one.
     */
    public function getAppEnv(): string
    {
        return $this->get('APP_ENV', 'production');
    }

    /**
     * Defaults to false when unset, for the same reason as getAppEnv().
     */
    public function isDebug(): bool
    {
        $value = $this->get('APP_DEBUG');
        return $value !== null && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Defaults to the process's current timezone (already set at the Docker/OS level)
     * when unset, so introducing this override doesn't shift timestamps for existing
     * deployments that haven't opted in via APP_TIMEZONE.
     */
    public function getTimezone(): string
    {
        return $this->get('APP_TIMEZONE', date_default_timezone_get());
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