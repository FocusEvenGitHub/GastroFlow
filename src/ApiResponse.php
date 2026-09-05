<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * One consistent error shape across every controller/middleware
 * (docs/ROADMAP.md's v1.7.0 "API error standardization", spec 024) —
 * {"success": false, "error": "...", "code": "..."}. Only for the specific,
 * anticipated cases each call site already knows about; an unanticipated
 * exception still goes through App.php's own global error handler, which
 * already produces this same shape for that case.
 */
final class ApiResponse
{
    public static function error(Response $response, int $status, string $code, string $message, array $extra = []): Response
    {
        $payload = array_merge(['success' => false, 'error' => $message, 'code' => $code], $extra);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
