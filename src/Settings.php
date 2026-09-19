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

    /**
     * How long (in seconds) a worker may hold a job reservation before the job is
     * considered abandoned and becomes claimable again.
     *
     * Defaults to 300s: far above a print job's real duration (seconds), far below a
     * shift, so a worker killed mid-job is recovered within five minutes without a
     * healthy slow job ever being stolen from the worker still running it.
     */
    public function getQueueReservationTimeout(): int
    {
        $value = (int) $this->get('QUEUE_RESERVATION_TIMEOUT', 300);
        return $value > 0 ? $value : 300;
    }

    /**
     * How many days a completed job is kept before being pruned.
     *
     * Defaults to 7. Failed jobs are never pruned by this setting — they are the
     * diagnostic record.
     */
    public function getQueueRetentionDays(): int
    {
        $value = (int) $this->get('QUEUE_RETENTION_DAYS', 7);
        return $value > 0 ? $value : 7;
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