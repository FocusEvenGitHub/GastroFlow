<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class VersionController
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /version
     *
     * Best-effort only: the production image has no `.git`, so it carries the version it
     * was built from in a `VERSION` file (build arg, spec 066), read first. Otherwise
     * `git describe` against the repo's own `.git` (bind-mounted read-only in the
     * development compose). Falls back to "dev" whenever neither is available — never
     * fails the request over a purely cosmetic version badge.
     */
    public function current(Request $request, Response $response): Response
    {
        $payload = ['success' => true, 'version' => $this->describe()];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function describe(): string
    {
        $versionFile = $this->settings->getBasePath() . '/VERSION';
        if (is_file($versionFile)) {
            $version = trim((string) @file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        $gitDir = $this->settings->getBasePath() . '/.git';
        if (!is_dir($gitDir) || !function_exists('shell_exec')) {
            return 'dev';
        }

        $command = 'git --git-dir=' . escapeshellarg($gitDir) . ' describe --tags --always 2>/dev/null';
        $output = @shell_exec($command);

        if (!is_string($output) || trim($output) === '') {
            return 'dev';
        }

        return trim($output);
    }
}
