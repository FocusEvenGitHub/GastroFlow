<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RequestContext;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Mirrors common/migrations/018_audit_log.sql. Keep in sync, or writes fail with
        // "no such column".
        Db::schema()->create('audit_log', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('username', 100)->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /** AC1-4 shape: a real HTTP-context actor is recorded correctly. */
    public function testRecordWritesActorFromContext(): void
    {
        $context = new RequestContext();
        $context->setUserId(7);
        $context->setUsername('admin_joao');
        $logger = new AuditLogger($context);

        $logger->record('menu_item.updated', 'menu_item', 42, ['price' => 29.9]);

        $this->assertSame(1, AuditLog::count());
        $row = AuditLog::first();
        $this->assertSame(7, $row->user_id);
        $this->assertSame('admin_joao', $row->username);
        $this->assertSame('menu_item.updated', $row->action);
        $this->assertSame('menu_item', $row->entity_type);
        $this->assertSame(42, $row->entity_id);
        $this->assertSame(['price' => 29.9], $row->details);
    }

    /** AC4 — the CLI path: a never-populated RequestContext must record a null actor, not a fake one. */
    public function testRecordWithUnpopulatedContextWritesNullActor(): void
    {
        $logger = new AuditLogger(new RequestContext());

        $logger->record('user.created', 'user', 5, ['username' => 'newuser', 'role' => 'cashier', 'source' => 'cli']);

        $row = AuditLog::first();
        $this->assertNull($row->user_id);
        $this->assertNull($row->username);
        $this->assertSame('cli', $row->details['source']);
    }

    /** entity_type/entity_id are genuinely optional (settings.updated has neither). */
    public function testEntityTypeAndIdAreOptional(): void
    {
        $logger = new AuditLogger(new RequestContext());

        $logger->record('settings.updated', null, null, ['printer_ip' => ['old' => null, 'new' => '10.0.0.5']]);

        $row = AuditLog::first();
        $this->assertNull($row->entity_type);
        $this->assertNull($row->entity_id);
        $this->assertSame(['old' => null, 'new' => '10.0.0.5'], $row->details['printer_ip']);
    }
}
