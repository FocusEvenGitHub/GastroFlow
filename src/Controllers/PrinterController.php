<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\PrintService;

class PrinterController
{
    public function __construct(
        private readonly PrintService $printService,
    ) {
    }

    /**
     * POST /api/admin/settings/test-print
     * Imprime um cupom de teste.
     */
    public function testPrint(Request $request, Response $response): Response
    {
        $this->printService->printTestPage();
        $payload = ['success' => true, 'message' => 'Teste enviado para a impressora.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
