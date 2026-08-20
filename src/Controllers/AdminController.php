<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Setting;
use App\Models\User;
use App\Services\PrintService;
use App\Settings;

class AdminController
{
    public function __construct(
        private readonly PrintService $printService,
        private readonly Settings $settings,
    ) {
    }

    /**
     * POST /api/admin/settings/test-print
     * Imprime um cupom de teste.
     */
    public function testPrint(Request $request, Response $response): Response
    {
        try {
            $this->printService->printTestPage();
            $payload = ['success' => true, 'message' => 'Teste enviado para a impressora.'];
        } catch (\Throwable $e) {
            $payload = ['error' => $e->getMessage()];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/admin/settings
     * Retorna todas as configurações.
     */
    public function getSettings(Request $request, Response $response): Response
    {
        $settings = Setting::getAll();
        $payload = ['success' => true, 'settings' => $settings];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /api/admin/settings
     * Atualiza uma ou mais configurações.
     * Body: { "settings": { "key": "value", ... } }
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $settings = $data['settings'] ?? [];

        if (empty($settings) || !is_array($settings)) {
            $response->getBody()->write(json_encode([
                'error' => 'Envie um objeto "settings" com as chaves/valores.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        foreach ($settings as $key => $value) {
            Setting::setValue($key, $value);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Configurações salvas com sucesso!'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
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

    /**
     * POST /api/admin/settings/logo
     * Faz upload da logo do restaurante (PNG/JPG).
     * Body: multipart/form-data com campo "logo"
     */
    public function uploadLogo(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $logoFile = $uploadedFiles['logo'] ?? null;

        if (!$logoFile || $logoFile->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode([
                'error' => 'Envie um arquivo de imagem válido no campo "logo".'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
        $fileType = $logoFile->getClientMediaType();

        if (!in_array($fileType, $allowedTypes, true)) {
            $response->getBody()->write(json_encode([
                'error' => 'Formato não suportado. Use PNG, JPG ou WebP.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $destDir = $this->settings->getPublicAssetsImgDir();
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destPath = $destDir . '/logo.png';
        $logoFile->moveTo($destPath);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Logo atualizada com sucesso!'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
