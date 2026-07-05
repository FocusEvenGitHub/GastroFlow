<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

class MigrationRunner
{
    private string $sqlDir;

    public function __construct(?string $sqlDir = null)
    {
        $this->sqlDir = $sqlDir ?? __DIR__ . '/../../common/migrations';
    }

    /**
     * Run all pending migrations (SQL files that haven't been executed yet).
     */
    public function run(): void
    {
        $this->ensureMigrationsTable();

        $files = $this->getPendingFiles();

        if (empty($files)) {
            echo "✔ Nenhuma migração pendente.\n";
            return;
        }

        foreach ($files as $file) {
            $name = $file->getFilename();
            echo "▶ Executando {$name} ... ";

            try {
                $sql = file_get_contents($file->getRealPath());
                if ($sql === false || trim($sql) === '') {
                    echo "[IGNORADO] (vazio)\n";
                    $this->markAsRun($name, md5(''));
                    continue;
                }

                Capsule::connection()->unprepared($sql);
                $this->markAsRun($name, md5($sql));

                echo "[OK]\n";
            } catch (\Throwable $e) {
                echo "[ERRO] " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    /**
     * Create the migrations control table if it doesn't exist.
     */
    private function ensureMigrationsTable(): void
    {
        if (Capsule::schema()->hasTable('migrations')) {
            return;
        }

        Capsule::schema()->create('migrations', function ($table) {
            $table->id();
            $table->string('migration', 200)->unique();
            $table->string('hash', 32);
            $table->timestamp('executed_at')->useCurrent();
        });
    }

    /**
     * Return all SQL files in the directory sorted by filename,
     * excluding those already registered in the migrations table.
     *
     * @return \SplFileInfo[]
     */
    private function getPendingFiles(): array
    {
        $ran = Capsule::table('migrations')->pluck('migration')->all();

        $iterator = new \DirectoryIterator($this->sqlDir);

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $name = $file->getFilename();
                if (!in_array($name, $ran, true)) {
                    $files[] = clone $file;
                }
            }
        }

        usort($files, fn(\SplFileInfo $a, \SplFileInfo $b) => strcmp($a->getFilename(), $b->getFilename()));

        return $files;
    }

    /**
     * Register a migration as executed.
     */
    private function markAsRun(string $name, string $hash): void
    {
        Capsule::table('migrations')->insert([
            'migration' => $name,
            'hash'      => $hash,
        ]);
    }
}
