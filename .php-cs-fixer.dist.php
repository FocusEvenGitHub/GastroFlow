<?php

declare(strict_types=1);

/**
 * Code style configuration for GastroFlow (spec 034).
 *
 * PSR-12 plus an explicit strict_types guard. Every PHP file under src/, bin/ and tests/
 * already declares strict_types (measured while drafting spec 034), so that rule protects
 * the status quo rather than changing it.
 *
 * public/ is excluded on purpose: those are plain PHP/HTML view scripts running outside
 * Slim (see CLAUDE.md), where a PSR-12 formatter fights the embedded markup.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([
        __DIR__ . '/bin/worker',
        __DIR__ . '/bin/migrate',
        __DIR__ . '/bin/create-admin',
        __DIR__ . '/bin/jobs-status',
        __DIR__ . '/bin/jobs-prune',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'              => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
