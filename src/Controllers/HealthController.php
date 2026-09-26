<?php

declare(strict_types=1);

namespace App\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;

class HealthController
{
    /**
     * GET /health/live
     *
     * Não verifica nenhuma dependência de propósito (spec 044) — só confirma que o
     * processo PHP está de pé e o Slim está roteando. Um probe de dependência
     * pertence a /health/ready.
     */
    public function live(Request $request, Response $response): Response
    {
        $payload = ['success' => true, 'status' => 'live'];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /health/ready
     *
     * A mensagem de erro é fixa (nunca a da exceção capturada) para que um erro real
     * de PDO/conexão, que pode citar host ou nome do banco, nunca vaze na resposta
     * (spec 044).
     */
    public function ready(Request $request, Response $response): Response
    {
        try {
            Capsule::connection()->select('select 1');
        } catch (\Throwable $e) {
            return ApiResponse::error(
                $response,
                503,
                'DB_UNAVAILABLE',
                'Banco de dados indisponível.'
            );
        }

        $payload = ['success' => true, 'status' => 'ready'];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
