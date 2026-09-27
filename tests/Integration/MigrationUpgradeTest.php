<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Database\MigrationRunner;
use App\Settings;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

/**
 * Spec 048: builds the database exactly as it stood at git tag v1.7.1 (base schema + every
 * migration file that existed at that tag, read via `git show` so the historical bytes are
 * exact rather than assumed), inserts one historical row, then runs the CURRENT
 * MigrationRunner to bring it to HEAD — proving both that the upgrade succeeds and that
 * historical data survives it.
 *
 * As a side effect, this exercises the real-world case that motivated the hash-divergence
 * normalization in MigrationRunner: 10 of the 14 migration files that existed at v1.7.1 have
 * different bytes today, purely from the CRLF -> LF normalization spec 034's `.gitattributes`
 * introduced. If that normalization were mistaken for a real edit, this test's second phase
 * (upgrading to HEAD) would fail with MigrationHashMismatchException.
 *
 * Deliberately does NOT extend IntegrationTestCase: that base class always builds the schema
 * once per process, at HEAD — the opposite of what this test needs. It uses its own
 * dedicated database instead, so building an old schema state here can never corrupt the
 * shared, always-at-HEAD schema the rest of the Integration suite depends on.
 */
class MigrationUpgradeTest extends TestCase
{
    private const UPGRADE_TAG = 'v1.7.1';

    private ?string $previousDatabase = null;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $testDatabase = self::envValue('MYSQL_DATABASE_TEST_UPGRADE');
        $liveDatabase = self::envValue('MYSQL_DATABASE') ?? 'restaurant';
        $sharedTestDatabase = self::envValue('MYSQL_DATABASE_TEST');

        if ($testDatabase === null || $testDatabase === '') {
            $this->markTestSkipped(
                'The migration upgrade test needs its own database. Set MYSQL_DATABASE_TEST_UPGRADE '
                . '(e.g. restaurant_test_upgrade) to a database that is NOT MYSQL_DATABASE or MYSQL_DATABASE_TEST.'
            );
        }

        if ($testDatabase === $liveDatabase || $testDatabase === $sharedTestDatabase) {
            $this->fail(sprintf(
                'MYSQL_DATABASE_TEST_UPGRADE ("%s") collides with another real/shared database. '
                . 'Refusing to run: this test drops and rebuilds every table in it.',
                $testDatabase
            ));
        }

        $this->previousDatabase = $_ENV['MYSQL_DATABASE'] ?? null;
        $_ENV['MYSQL_DATABASE'] = $testDatabase;
        Database::boot(new Settings());

        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*.sql') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        if ($this->previousDatabase !== null) {
            $_ENV['MYSQL_DATABASE'] = $this->previousDatabase;
        } else {
            unset($_ENV['MYSQL_DATABASE']);
        }
    }

    public function testUpgradeFromV171PreservesHistoricalOrderAndReachesHead(): void
    {
        $this->buildTaggedState(self::UPGRADE_TAG);

        $orderId = (int) Db::table('orders')->insertGetId([
            'order_number'  => 'HIST-1',
            'business_date' => '2026-01-01',
            'status'        => 'done',
            'created_at'    => '2026-01-01 12:00:00',
        ]);

        ob_start();
        try {
            (new MigrationRunner())->run();
        } finally {
            ob_end_clean();
        }

        $historicalOrder = Db::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($historicalOrder, 'The historical order did not survive the upgrade.');
        $this->assertSame('HIST-1', $historicalOrder->order_number);
        $this->assertSame('2026-01-01', substr((string) $historicalOrder->business_date, 0, 10));
        $this->assertSame('done', $historicalOrder->status);

        // A post-v1.7.1 migration (018_audit_log.sql, spec 043) proves the upgrade actually
        // reached HEAD rather than being a no-op.
        $this->assertTrue(Db::schema()->hasTable('audit_log'));
    }

    /**
     * Builds the database exactly as it stood at $tag: 001_schema.sql (unchanged since v1.7.1,
     * confirmed by `git diff v1.7.1 -- common/sql/001_schema.sql` producing no output) plus
     * every migration file that existed in common/migrations/ at that tag, materialized into a
     * temp directory from its exact historical bytes.
     */
    private function buildTaggedState(string $tag): void
    {
        $repoRoot = dirname(__DIR__, 2);

        $schemaSql = file_get_contents($repoRoot . '/common/sql/001_schema.sql');
        if ($schemaSql === false) {
            $this->fail('Could not read common/sql/001_schema.sql');
        }

        // Same stripping IntegrationTestCase::buildSchema() does: the file hardcodes the live
        // database name in a CREATE DATABASE/USE header, which would otherwise switch this
        // connection onto it.
        $schemaSql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;/is', '', $schemaSql) ?? $schemaSql;
        $schemaSql = preg_replace('/^\s*USE\s+[^;]+;/im', '', $schemaSql) ?? $schemaSql;
        Db::connection()->unprepared($schemaSql);

        $tempDir = sys_get_temp_dir() . '/gastroflow-migration-upgrade-test-' . uniqid();
        mkdir($tempDir);
        $this->tempDirs[] = $tempDir;

        // -c safe.directory=* only affects this one invocation, never a shared git config
        // file: needed because the repo may be bind-mounted from a different owner (e.g. a
        // Windows host into this Linux container) than the process running phpunit.
        $listing = shell_exec(sprintf(
            'git -c safe.directory=%s -C %s ls-tree -r --name-only %s -- common/migrations 2>&1',
            escapeshellarg('*'),
            escapeshellarg($repoRoot),
            escapeshellarg($tag)
        ));
        if (!is_string($listing) || trim($listing) === '') {
            $this->fail(
                "Could not list common/migrations/ at tag {$tag} via git. Is a full clone "
                . '(fetch-depth: 0 in CI) available, with tags fetched?'
            );
        }

        foreach (preg_split('/\r?\n/', trim($listing)) as $path) {
            if ($path === '') {
                continue;
            }

            $content = shell_exec(sprintf(
                'git -c safe.directory=%s -C %s show %s 2>/dev/null',
                escapeshellarg('*'),
                escapeshellarg($repoRoot),
                escapeshellarg("{$tag}:{$path}")
            ));
            if (!is_string($content) || $content === '') {
                $this->fail("Could not read {$path} at tag {$tag} via git.");
            }

            file_put_contents($tempDir . '/' . basename($path), $content);
        }

        ob_start();
        try {
            (new MigrationRunner($tempDir))->run();
        } finally {
            ob_end_clean();
        }
    }

    private function dropAllTables(): void
    {
        $tables = Db::connection()->select('SHOW TABLES');
        if (empty($tables)) {
            return;
        }

        Db::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $row) {
            $tableName = (string) array_values((array) $row)[0];
            Db::connection()->statement('DROP TABLE IF EXISTS `' . $tableName . '`');
        }
        Db::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Mirrors IntegrationTestCase::envValue() — CI supplies configuration as real env vars. */
    private static function envValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '') {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? null : $fromEnv;
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
