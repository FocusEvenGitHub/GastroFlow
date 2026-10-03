<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LoginThrottleService;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

/**
 * Spec 053 — login throttling policy against in-memory SQLite, with a
 * controlled clock so the 15-minute window is tested without waiting.
 */
class LoginThrottleServiceTest extends TestCase
{
    private int $now;
    private LoginThrottleService $throttle;

    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('login_attempts', function ($table) {
            $table->id();
            $table->string('ip', 45);
            $table->string('username', 100);
            $table->dateTime('attempted_at');
        });

        $this->now = strtotime('2026-10-02 12:00:00');
        $this->throttle = new LoginThrottleService(fn (): int => $this->now);
    }

    private function failLogins(string $ip, string $username, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttle->recordFailure($ip, $username);
            $this->now += 10;
        }
    }

    /** AC1 */
    public function testTenFailuresBlockAndNineDoNot(): void
    {
        $this->failLogins('1.1.1.1', 'admin', 9);
        $this->assertNull($this->throttle->check('1.1.1.1', 'admin'));

        $this->failLogins('1.1.1.1', 'admin', 1);
        $retry = $this->throttle->check('1.1.1.1', 'admin');
        $this->assertNotNull($retry);
        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(LoginThrottleService::WINDOW_SECONDS, $retry);
    }

    /** AC2 */
    public function testBlockExpiresAfterTheWindowAndOldRowsArePruned(): void
    {
        $this->failLogins('1.1.1.1', 'admin', 10);
        $lastFailureAt = $this->now - 10;
        $this->assertNotNull($this->throttle->check('1.1.1.1', 'admin'));

        $this->now = $lastFailureAt + LoginThrottleService::WINDOW_SECONDS + 1;
        $this->assertNull($this->throttle->check('1.1.1.1', 'admin'));

        $this->throttle->recordFailure('2.2.2.2', 'someone');
        $this->assertSame(1, Db::table('login_attempts')->count(), 'rows outside the window are deleted');
    }

    /** AC3 */
    public function testPerIpLimitAcrossUsernamesAndKeysAreIsolated(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->failLogins('3.3.3.3', 'user' . $i, 1);
        }
        $this->assertNotNull($this->throttle->check('3.3.3.3', 'brand-new-user'));
        $this->assertNull($this->throttle->check('4.4.4.4', 'brand-new-user'));

        $this->failLogins('5.5.5.5', 'admin', 10);
        $this->assertNotNull($this->throttle->check('5.5.5.5', 'admin'));
        $this->assertNull($this->throttle->check('6.6.6.6', 'admin'), 'same user, other IP is not blocked');
        $this->assertNull($this->throttle->check('5.5.5.5', 'other'), 'other user, same IP, under the per-IP limit');
    }

    /** AC4 */
    public function testClearResetsAndUsernamesAreNormalized(): void
    {
        $this->failLogins('1.1.1.1', 'admin', 9);
        $this->throttle->clear('1.1.1.1', 'admin');
        $this->failLogins('1.1.1.1', 'admin', 1);
        $this->assertNull($this->throttle->check('1.1.1.1', 'admin'));

        $this->failLogins('7.7.7.7', 'Admin', 5);
        $this->failLogins('7.7.7.7', ' admin ', 5);
        $this->assertNotNull($this->throttle->check('7.7.7.7', 'ADMIN'));
    }
}
