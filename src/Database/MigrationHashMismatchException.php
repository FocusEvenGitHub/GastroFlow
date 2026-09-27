<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Thrown by MigrationRunner::run() when an already-tracked, still-present migration file's
 * current content no longer matches what was recorded at apply time (spec 048).
 */
class MigrationHashMismatchException extends \RuntimeException
{
}
