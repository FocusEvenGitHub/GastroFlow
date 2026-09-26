<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class VersionController
{
    /**
     * GET /version
     *
     * Best-effort only: reads `git describe` against the repo's own `.git`
     * (bind-mounted read-only in docker-compose.yml). Falls back to "dev"
     * whenever that mount, or the `git` binary, isn't available — never fails
     * the request over a purely cosmetic version badge.
     */
    public function current(Request $request, Response $response): Response
    {
        $payload = ['success' => true, 'version' => $this->describe()];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function describe(): string
    {
        $gitDir = dirname(__DIR__, 2) . '/.git';
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
