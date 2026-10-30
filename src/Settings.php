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

    /**
     * How many days a realtime event is kept before being pruned (spec 041).
     *
     * Defaults to 2. These rows exist so a reconnecting kitchen screen can catch up on what
     * it missed (Last-Event-ID) — not as an audit trail — so the window is deliberately
     * short: long enough to cover an overnight disconnect, short enough that nobody mistakes
     * `events` for order history (that's what `orders`/`order_items` already are).
     */
    public function getEventsRetentionDays(): int
    {
        $value = (int) $this->get('EVENTS_RETENTION_DAYS', 2);
        return $value > 0 ? $value : 2;
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

    /**
     * Where uploads land (spec 066): outside the code tree's tracked files, so the
     * production compose can mount it as a volume that survives an image swap.
     */
    public function getUploadsDir(): string
    {
        return $this->getPublicDir() . '/uploads';
    }

    public function getUploadedLogoPath(): string
    {
        return $this->getUploadsDir() . '/logo.png';
    }

    /**
     * The GastroFlow logo shipped with the code (tracked in git). Before spec 066 uploads
     * overwrote this very file, so on an install that set its own logo it still holds it.
     */
    public function getDefaultLogoPath(): string
    {
        return $this->getPublicAssetsImgDir() . '/logo.png';
    }

    /** The logo in use: the uploaded one, else the default (spec 066). */
    public function getLogoPath(): string
    {
        $uploaded = $this->getUploadedLogoPath();
        return file_exists($uploaded) ? $uploaded : $this->getDefaultLogoPath();
    }

    /** Public URL of getLogoPath(), or null when there is no logo file at all. */
    public function getLogoUrl(): ?string
    {
        if (file_exists($this->getUploadedLogoPath())) {
            return '/uploads/logo.png';
        }
        return file_exists($this->getDefaultLogoPath()) ? '/assets/img/logo.png' : null;
    }
}
