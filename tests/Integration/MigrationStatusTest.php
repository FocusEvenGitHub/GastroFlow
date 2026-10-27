<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\HealthController;
use App\Database\MigrationRunner;
use Illuminate\Database\Capsule\Manager as Db;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Spec 063: the read-only pending list behind `bin/migrate --status` and `GET /health/ready`.
 * The test database is fully migrated by IntegrationTestCase; a "pending" migration is
 * simulated with a temp directory holding every real file plus one extra, so nothing real
 * is ever applied or recorded.
 */
class MigrationStatusTest extends IntegrationTestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            array_map('unlink', glob($this->tempDir . '/*.sql') ?: []);
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testNothingPendingOnAMigratedDatabase(): void
    {
        $this->assertSame([], (new MigrationRunner())->pending());

        $response = $this->request('GET', '/health/ready');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPendingListsUnrecordedFilesWithoutWritingAnything(): void
    {
        $before = Db::table('migrations')->count();

        $this->assertSame(['999_not_applied.sql'], $this->runnerWithOneExtraMigration()->pending());
        $this->assertSame($before, Db::table('migrations')->count());
    }

    public function testHealthReadyAnswers503WhileAMigrationIsPending(): void
    {
        $controller = new HealthController($this->runnerWithOneExtraMigration());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health/ready');

        $response = $controller->ready($request, (new ResponseFactory())->createResponse());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('MIGRATIONS_PENDING', $this->decode($response)['code'] ?? null);
    }

    private function runnerWithOneExtraMigration(): MigrationRunner
    {
        $this->tempDir = sys_get_temp_dir() . '/gastroflow-migration-status-' . uniqid();
        mkdir($this->tempDir);
        foreach (glob(dirname(__DIR__, 2) . '/common/migrations/*.sql') ?: [] as $file) {
            copy($file, $this->tempDir . '/' . basename($file));
        }
        file_put_contents($this->tempDir . '/999_not_applied.sql', "SELECT 1;\n");

        return new MigrationRunner($this->tempDir);
    }
}
