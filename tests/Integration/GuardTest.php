<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Proves the suite is pointed at the dedicated test database and not at the live one.
 *
 * This is the guard the rest of the suite rests on: every other test in tests/Integration
 * deletes rows in setUp(), so if the guard were wrong it would destroy real data. A guard
 * that has never been exercised is not a guard, which is why it gets its own test.
 */
class GuardTest extends IntegrationTestCase
{
    public function testRunsAgainstTheDedicatedTestDatabase(): void
    {
        // Read the name captured before setUp() redirected the app: MYSQL_DATABASE now
        // holds the test database, so comparing against it would compare a value to itself.
        $this->assertNotSame(
            $this->liveDatabase(),
            $this->testDatabase(),
            'The test database must never be the live one'
        );
    }

    public function testConnectionIsActuallyOnTheTestDatabase(): void
    {
        $current = \Illuminate\Database\Capsule\Manager::connection()
            ->selectOne('SELECT DATABASE() AS db');

        $this->assertSame(
            $this->testDatabase(),
            $current->db,
            'MySQL reports a different current database than MYSQL_DATABASE_TEST — '
            . 'the connection is not where we think it is'
        );
    }

    public function testMigrationChainProducedTheExpectedTables(): void
    {
        $tables = \Illuminate\Database\Capsule\Manager::connection()
            ->select('SHOW TABLES');

        $names = array_map(static fn ($row) => current((array) $row), $tables);

        // A sample across the chain: base schema, an early migration, and spec 033's.
        foreach (['categories', 'menu_items', 'orders', 'jobs', 'order_item_components'] as $expected) {
            $this->assertContains($expected, $names, "Missing table: {$expected}");
        }
    }
}
