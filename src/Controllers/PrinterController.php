<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Services\PrintService;

class PrinterController
{
    public function __construct(
        private readonly PrintService $printService,
    ) {
    }

    /**
     * GET /api/printer/status
     *
     * Público, como /api/orders* e /api/kitchen/* (spec 018): a cozinha e o caixa não têm
     * tela de login, então um endpoint protegido seria inútil justamente para as duas telas
     * que precisam dele. Não expõe dado de cliente nem credencial — só se a impressora está
     * com problema, e a mensagem de erro já saneada pela spec 038.
     */
    public function status(Request $request, Response $response): Response
    {
        $payload = ['success' => true] + $this->printService->getPrinterStatus();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/printer/reset
     *
     * Reativação manual. Idempotente e não destrutiva: não apaga jobs, não altera pedidos e
     * não muda a configuração da impressora. Os jobs já em `failed` continuam `failed` — a
     * spec 033 os preserva de propósito como registro de diagnóstico.
     */
    public function reset(Request $request, Response $response): Response
    {
        $this->printService->resetPrinterFailures();

        $payload = ['success' => true] + $this->printService->getPrinterStatus();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/admin/settings/test-print
     * Imprime um cupom de teste.
     */
    public function testPrint(Request $request, Response $response): Response
    {
        try {
            $this->printService->printTestPage();
        } catch (\Throwable $e) {
            // Without this, the exception reached App.php's global handler as a 500 and, under
            // APP_ENV=production, spec 012 sanitised it to "Erro interno do servidor" — so the
            // one screen built to diagnose the printer could not say what was wrong with it
            // (spec 038). 503: a dependency is unavailable, not a bad request or a server bug.
            return ApiResponse::error(
                $response,
                503,
                'PRINTER_UNAVAILABLE',
                $this->describePrinterFailure($e)
            );
        }

        $payload = ['success' => true, 'message' => 'Teste enviado para a impressora.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Turn a printing failure into something an operator can act on.
     *
     * Deliberately built from known facts rather than echoing the exception: the raw message
     * can carry file paths and class names, and this response is intentionally more
     * informative than the production error sanitiser would allow.
     */
    private function describePrinterFailure(\Throwable $e): string
    {
        if ($e->getMessage() === PrintService::ERROR_NO_IP) {
            return PrintService::ERROR_NO_IP
                . ' Defina o IP em Configurações antes de testar a impressão.';
        }

        $address = $this->printService->getConfiguredAddress();

        if ($address === null) {
            return PrintService::ERROR_NO_IP
                . ' Defina o IP em Configurações antes de testar a impressão.';
        }

        return sprintf(
            'Não foi possível imprimir em %s. Verifique se a impressora está ligada e na mesma rede.',
            $address
        );
    }
}
