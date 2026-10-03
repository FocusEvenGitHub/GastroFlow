<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Login throttling (spec 053): limits failed POST /api/login attempts per
 * client IP + username, plus a higher limit per IP across usernames, so one
 * machine can't brute-force a password or spray usernames. Keyed on IP +
 * username so an attacker can only lock an account out on their own IP.
 *
 * Blocked attempts are never recorded, so a blocked client can't extend its
 * own block: it ends WINDOW_SECONDS after the failure that reached the limit.
 */
class LoginThrottleService
{
    public const MAX_FAILURES_PER_USER = 10;
    public const MAX_FAILURES_PER_IP = 30;
    public const WINDOW_SECONDS = 900;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock current Unix time; injectable so
     *        tests don't have to wait out the window.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Seconds until this IP + username may try again, or null if allowed.
     */
    public function check(string $ip, string $username): ?int
    {
        $username = self::normalize($username);
        $cutoff = $this->cutoff();

        $retry = null;
        foreach ([
            [['ip' => $ip, 'username' => $username], self::MAX_FAILURES_PER_USER],
            [['ip' => $ip], self::MAX_FAILURES_PER_IP],
        ] as [$where, $limit]) {
            $failures = DB::table('login_attempts')->where($where)->where('attempted_at', '>', $cutoff);
            if ((clone $failures)->count() < $limit) {
                continue;
            }
            $latest = strtotime((string) $failures->max('attempted_at'));
            $seconds = $latest + self::WINDOW_SECONDS - $this->now();
            $retry = max($retry ?? 0, $seconds);
        }

        return $retry === null ? null : max(1, $retry);
    }

    public function recordFailure(string $ip, string $username): void
    {
        DB::table('login_attempts')->insert([
            'ip'           => $ip,
            'username'     => self::normalize($username),
            'attempted_at' => date('Y-m-d H:i:s', $this->now()),
        ]);
        // Nothing outside the window is ever read again — keep the table small.
        DB::table('login_attempts')->where('attempted_at', '<=', $this->cutoff())->delete();
    }

    public function clear(string $ip, string $username): void
    {
        DB::table('login_attempts')->where(['ip' => $ip, 'username' => self::normalize($username)])->delete();
    }

    /** `Admin` and ` admin ` share one counter; the users lookup itself is unchanged. */
    public static function normalize(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username)), 0, 100);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function cutoff(): string
    {
        return date('Y-m-d H:i:s', $this->now() - self::WINDOW_SECONDS);
    }
}
