<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Settings;

class LogController
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * GET /api/admin/logs
     * Retorna as últimas N linhas do arquivo de log.
     */
    public function getLogs(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $lines = min(max((int)($params['lines'] ?? 200), 10), 5000);

        $logFile = $this->settings->getLogFile();

        if (!file_exists($logFile)) {
            $payload = ['success' => true, 'lines' => [], 'file' => 'app.log'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $content = file_get_contents($logFile);
        if ($content === false || $content === '') {
            $payload = ['success' => true, 'lines' => [], 'file' => 'app.log'];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $allLines = explode("\n", $content);
        // Remove trailing empty line
        if (end($allLines) === '') {
            array_pop($allLines);
        }
        $lastLines = array_slice($allLines, -$lines);
        // Reverse so newest appears first
        $lastLines = array_reverse($lastLines);

        $payload = [
            'success' => true,
            'lines'   => $lastLines,
            'total'   => count($allLines),
            'file'    => 'app.log',
        ];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
