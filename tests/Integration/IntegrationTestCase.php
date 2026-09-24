<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Database\MigrationRunner;
use App\Settings;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base class for MySQL-backed integration tests (spec 035).
 *
 * These tests write real rows, so they must never touch the database an operator or
 * developer actually uses. Two guards enforce that, and the safe outcome is the default:
 *
 *  - MYSQL_DATABASE_TEST unset  -> every test is SKIPPED. Running `vendor/bin/phpunit`
 *    with no extra configuration therefore behaves exactly as it did before this suite.
 *  - MYSQL_DATABASE_TEST equal to MYSQL_DATABASE -> the test FAILS loudly. A
 *    misconfiguration that would destroy real data must not be silently tolerated.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Schema is built once per process, not per test — see spec 035's runtime note. */
    private static bool $schemaReady = false;

    /** Tables cleared between tests. Order matters: children before parents (FKs). */
    private const VOLATILE_TABLES = [
        'order_item_components',
        'order_items',
        'orders',
        'order_number_counters',
        'jobs',
        'users',
    ];

    /** The live database name, captured before setUp() redirects the app at the test one. */
    private ?string $previousDatabase = null;

    protected function setUp(): void
    {
        $testDatabase = self::envValue('MYSQL_DATABASE_TEST');
        $liveDatabase = self::envValue('MYSQL_DATABASE') ?? 'restaurant';

        if ($testDatabase === null || $testDatabase === '') {
            $this->markTestSkipped(
                'Integration tests need a dedicated database. Set MYSQL_DATABASE_TEST '
                . '(e.g. restaurant_test) to a database that is NOT MYSQL_DATABASE.'
            );
        }

        if ($testDatabase === $liveDatabase) {
            $this->fail(sprintf(
                'MYSQL_DATABASE_TEST ("%s") is the same database as MYSQL_DATABASE. '
                . 'Refusing to run: these tests delete rows, and that would destroy real data.',
                $testDatabase
            ));
        }

        // Point the whole application — Settings, Database::boot(), the Slim app — at the
        // test database for the duration of this test, and put it back afterwards so the
        // Smoke suite keeps reading what it expects.
        $this->previousDatabase = $_ENV['MYSQL_DATABASE'] ?? null;
        $_ENV['MYSQL_DATABASE'] = $testDatabase;

        if (!self::$schemaReady) {
            $this->buildSchema();
            self::$schemaReady = true;
        }

        Database::boot(new Settings());
        $this->clearVolatileTables();
    }

    protected function tearDown(): void
    {
        if ($this->previousDatabase !== null) {
            $_ENV['MYSQL_DATABASE'] = $this->previousDatabase;
        } else {
            unset($_ENV['MYSQL_DATABASE']);
        }
    }

    /**
     * Build the test schema through the real chain: 001_schema.sql, then every migration
     * via MigrationRunner — the same path bin/migrate takes. Using the real chain means
     * these tests exercise the migrations rather than a hand-copied DDL that can drift.
     */
    private function buildSchema(): void
    {
        Database::boot(new Settings());

        $sql = file_get_contents(dirname(__DIR__, 2) . '/common/sql/001_schema.sql');
        if ($sql === false) {
            $this->fail('Could not read common/sql/001_schema.sql');
        }

        // 001_schema.sql opens with `CREATE DATABASE IF NOT EXISTS restaurant;` and
        // `USE restaurant;`, both hardcoded to the live database name. Executed verbatim
        // they would switch this connection onto the developer's real database and create
        // tables there. Strip them; the connection is already on the test database.
        $sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*USE\s+[^;]+;/im', '', $sql) ?? $sql;

        Db::connection()->unprepared($sql);

        // MigrationRunner reports progress on stdout; keep the test output readable.
        ob_start();
        (new MigrationRunner())->run();
        ob_end_clean();
    }

    private function clearVolatileTables(): void
    {
        Db::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::VOLATILE_TABLES as $table) {
            Db::connection()->table($table)->delete();
        }
        Db::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Settings reads $_ENV only, but CI supplies configuration as real environment
     * variables — tests/bootstrap.php bridges those, and this mirrors that fallback.
     */
    protected static function envValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '') {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? null : $fromEnv;
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** The database these tests are allowed to touch. */
    protected function testDatabase(): string
    {
        return (string) self::envValue('MYSQL_DATABASE_TEST');
    }

    /**
     * The live database name as it was before setUp() redirected the application.
     * Reading MYSQL_DATABASE during a test would return the test database, not this.
     */
    protected function liveDatabase(): ?string
    {
        return $this->previousDatabase;
    }

    /**
     * Send a request through the real Slim application.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = []
    ): \Psr\Http\Message\ResponseInterface {
        $app = (new \App\App(new Settings()))->get();

        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode($body));
            $request->getBody()->rewind();
        }

        return $app->handle($request);
    }

    /**
     * Create a user through the same hashing path bin/create-admin uses.
     * The password is generated per run — never a fixed or real credential.
     *
     * @return array{username: string, password: string}
     */
    protected function createUser(string $role = 'admin'): array
    {
        $username = $role . '_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(8));

        \App\Models\User::create([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role'     => $role,
        ]);

        return ['username' => $username, 'password' => $password];
    }

    /** Log in through the real endpoint and return the JWT. */
    protected function tokenFor(string $role = 'admin'): string
    {
        $user = $this->createUser($role);

        $response = $this->request('POST', '/api/login', [
            'username' => $user['username'],
            'password' => $user['password'],
        ]);

        $body = $this->decode($response);
        $this->assertSame(200, $response->getStatusCode(), 'Fixture login failed');

        return (string) $body['token'];
    }

    /** @return array{Authorization: string} */
    protected function authHeader(string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokenFor($role)];
    }

    /**
     * An available, non-customizable menu item from the seeded reference data.
     * Reference rows (categories/menu_items) survive between tests on purpose —
     * only transactional tables are cleared.
     */
    protected function availableMenuItem(): \App\Models\MenuItem
    {
        $item = \App\Models\MenuItem::where('available', 1)
            ->where('is_customizable', 0)
            ->orderBy('id')
            ->first();

        if (!$item) {
            $this->fail('No available menu item in the test database — schema seed missing?');
        }

        return $item;
    }

    /** @return array<string, mixed> */
    protected function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
