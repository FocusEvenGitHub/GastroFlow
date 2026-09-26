<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuditLogController
{
    /**
     * GET /api/admin/audit-log
     * Retorna as entradas mais recentes do histórico de auditoria, mais novas primeiro.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = min(max((int) ($params['limit'] ?? 100), 10), 5000);

        $entries = AuditLog::orderByDesc('id')->limit($limit)->get();

        $payload = [
            'success' => true,
            'entries' => $entries,
            'total'   => AuditLog::count(),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
