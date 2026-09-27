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
     *
     * @param bool $trustCurrentHashes When true, instead of applying pending migrations,
     *                                 re-baseline the recorded hash of every already-tracked,
     *                                 still-present migration to match its current on-disk
     *                                 content. Explicit, non-default reconciliation for cases
     *                                 like line-ending normalization changing bytes without
     *                                 changing meaning (spec 048) — never runs automatically.
     */
    public function run(bool $trustCurrentHashes = false): void
    {
        $this->ensureMigrationsTable();

        if ($trustCurrentHashes) {
            $this->reconcileHashes();
            return;
        }

        $this->assertNoHashDivergence();

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
                    $this->markAsRun($name, $this->normalizedHash(''));
                    continue;
                }

                Capsule::connection()->unprepared($sql);
                $this->markAsRun($name, $this->normalizedHash($sql));

                echo "[OK]\n";
            } catch (\Throwable $e) {
                echo "[ERRO] " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    /**
     * Abort before applying anything if an already-tracked, still-present migration file's
     * current content no longer matches what was recorded at apply time. A migration whose
     * file was deleted (not edited) is not checked here — a related but distinct gap, left
     * out of scope for spec 048 (see its Open questions).
     */
    private function assertNoHashDivergence(): void
    {
        $mismatches = [];

        foreach ($this->trackedFilesOnDisk() as $path => $migration) {
            $currentHash = $this->normalizedHash((string) file_get_contents($path));
            if ($currentHash !== $migration['hash']) {
                $mismatches[] = sprintf(
                    '%s (registrado %s…, atual %s…)',
                    $migration['name'],
                    substr($migration['hash'], 0, 8),
                    substr($currentHash, 0, 8)
                );
            }
        }

        if (!empty($mismatches)) {
            throw new MigrationHashMismatchException(
                "Migração(ões) já aplicada(s) têm conteúdo diferente do que foi registrado:\n - "
                . implode("\n - ", $mismatches)
                . "\nSe a mudança for esperada (ex.: normalização de fim de linha), rode "
                . "`bin/migrate --trust-current-hashes` uma vez para atualizar o hash registrado, "
                . "depois rode `bin/migrate` normalmente."
            );
        }
    }

    /**
     * Re-baseline the recorded hash of every already-tracked, still-present migration to
     * match its current on-disk content. Explicit and non-default — see run()'s docblock.
     */
    private function reconcileHashes(): void
    {
        $reconciled = [];

        foreach ($this->trackedFilesOnDisk() as $path => $migration) {
            $currentHash = $this->normalizedHash((string) file_get_contents($path));
            if ($currentHash !== $migration['hash']) {
                Capsule::table('migrations')
                    ->where('migration', $migration['name'])
                    ->update(['hash' => $currentHash]);
                $reconciled[] = $migration['name'];
            }
        }

        if (empty($reconciled)) {
            echo "✔ Nenhum hash divergente para reconciliar.\n";
            return;
        }

        echo "✔ Hash atualizado para: " . implode(', ', $reconciled) . "\n";
    }

    /**
     * Every migration already tracked in the `migrations` table whose file still exists in
     * $sqlDir. A tracked migration whose file was deleted is skipped, not yielded — see
     * assertNoHashDivergence()'s docblock.
     *
     * @return iterable<string, array{name: string, hash: string}> yields path => [name, hash]
     */
    private function trackedFilesOnDisk(): iterable
    {
        $tracked = Capsule::table('migrations')->get(['migration', 'hash']);

        foreach ($tracked as $row) {
            $path = $this->sqlDir . '/' . $row->migration;
            if (is_file($path)) {
                yield $path => ['name' => $row->migration, 'hash' => $row->hash];
            }
        }
    }

    /**
     * Hash used for both recording and comparing migration content, normalized so that a
     * line-ending-only change (e.g. CRLF -> LF from a `.gitattributes` policy, spec 034) is
     * never mistaken for a real edit (spec 048).
     */
    private function normalizedHash(string $sql): string
    {
        return md5(str_replace("\r\n", "\n", $sql));
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

        usort($files, fn (\SplFileInfo $a, \SplFileInfo $b) => strcmp($a->getFilename(), $b->getFilename()));

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
