<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\MigrationHashMismatchException;
use App\Database\MigrationRunner;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

/**
 * Spec 048: hash-divergence detection and the explicit --trust-current-hashes
 * reconciliation path. SQLite in-memory, matching the existing Unit-suite
 * pattern (e.g. JobServiceTest) — no MySQL needed for this logic.
 */
class MigrationRunnerTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->migrationsDir = sys_get_temp_dir() . '/gastroflow-migrations-test-' . uniqid();
        mkdir($this->migrationsDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.sql') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->migrationsDir);
    }

    private function writeFixture(string $name, string $sql): void
    {
        file_put_contents($this->migrationsDir . '/' . $name, $sql);
    }

    /** Runs $runner->run() while discarding its progress output, matching IntegrationTestCase's pattern. */
    private function runQuietly(MigrationRunner $runner, bool $trustCurrentHashes = false): void
    {
        ob_start();
        try {
            $runner->run($trustCurrentHashes);
        } finally {
            ob_end_clean();
        }
    }

    public function testAppliesPendingMigrationAndRecordsItsHash(): void
    {
        $this->writeFixture('001_create_widgets.sql', 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);');

        $this->runQuietly(new MigrationRunner($this->migrationsDir));

        $this->assertTrue(Db::schema()->hasTable('widgets'));
        $this->assertSame(
            '001_create_widgets.sql',
            Db::table('migrations')->value('migration')
        );
    }

    public function testRerunWithUnchangedFilesIsANoOpAndDoesNotThrow(): void
    {
        $this->writeFixture('001_create_widgets.sql', 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);');

        $runner = new MigrationRunner($this->migrationsDir);
        $this->runQuietly($runner);
        $this->runQuietly($runner);

        $this->assertSame(1, Db::table('migrations')->count());
    }

    public function testLineEndingOnlyChangeIsNotReportedAsDivergence(): void
    {
        $sql = "CREATE TABLE widgets (\n    id INTEGER PRIMARY KEY,\n    name TEXT\n);";
        $this->writeFixture('001_create_widgets.sql', $sql);

        $runner = new MigrationRunner($this->migrationsDir);
        $this->runQuietly($runner);

        // Same content, CRLF line endings instead of LF — exactly the spec 034 renormalization case.
        $this->writeFixture('001_create_widgets.sql', str_replace("\n", "\r\n", $sql));

        $this->runQuietly($runner);

        $this->assertTrue(Db::schema()->hasTable('widgets'));
    }

    public function testGenuineContentChangeThrowsBeforeApplyingAnyPendingMigration(): void
    {
        $this->writeFixture('001_create_widgets.sql', 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);');

        $runner = new MigrationRunner($this->migrationsDir);
        $this->runQuietly($runner);

        // A genuine edit to the already-applied migration, not just a line-ending change.
        $this->writeFixture(
            '001_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT, price INTEGER);'
        );
        // A second, still-pending migration that must NOT run once divergence is detected.
        $this->writeFixture('002_create_gadgets.sql', 'CREATE TABLE gadgets (id INTEGER PRIMARY KEY);');

        $this->expectException(MigrationHashMismatchException::class);
        $this->expectExceptionMessage('001_create_widgets.sql');

        try {
            $this->runQuietly($runner);
        } finally {
            $this->assertFalse(
                Db::schema()->hasTable('gadgets'),
                'A pending migration ran despite an unresolved hash divergence.'
            );
        }
    }

    public function testTrustCurrentHashesReconcilesDivergenceAndUnblocksTheNextRun(): void
    {
        $this->writeFixture('001_create_widgets.sql', 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT);');

        $runner = new MigrationRunner($this->migrationsDir);
        $this->runQuietly($runner);

        $editedSql = 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT, price INTEGER);';
        $this->writeFixture('001_create_widgets.sql', $editedSql);
        $this->writeFixture('002_create_gadgets.sql', 'CREATE TABLE gadgets (id INTEGER PRIMARY KEY);');

        // Reconcile: accepts the edited file's current content as the new baseline.
        $this->runQuietly($runner, trustCurrentHashes: true);

        $this->assertSame(
            md5($editedSql),
            Db::table('migrations')->where('migration', '001_create_widgets.sql')->value('hash')
        );
        // Reconciliation itself must not have applied the still-pending migration.
        $this->assertFalse(Db::schema()->hasTable('gadgets'));

        // A normal run now proceeds without throwing, and applies what was actually pending.
        $this->runQuietly($runner);

        $this->assertTrue(Db::schema()->hasTable('gadgets'));
    }
}
