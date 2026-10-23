<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Settings;
use App\Validators\SettingsValidator;

class SettingsController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SettingsValidator $settingsValidator,
        private readonly AuditLogger $auditLogger,
    ) {
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
     * GET /api/settings/discount — público, como /api/menu (spec 058): o Caixa e o
     * "Editar Pedido" da Cozinha precisam do teto para validar enquanto o operador
     * digita. O servidor valida de novo ao gravar; isto é só para a tela.
     */
    public function discountSettings(Request $request, Response $response): Response
    {
        $raw = Setting::getValue('discount_max_percent');
        $max = ($raw !== null && is_numeric($raw)) ? max(0.0, min(100.0, (float) $raw)) : 100.0;
        $response->getBody()->write(json_encode(['discount_max_percent' => $max]));
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
            return ApiResponse::error($response, 400, 'MISSING_REQUIRED_FIELDS', 'Envie um objeto "settings" com as chaves/valores.');
        }
        if (!$this->settingsValidator->validate($settings)) {
            $errors = $this->settingsValidator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
        }

        // Read the old value before overwriting it — this is the one call site where a real
        // before/after costs nothing extra, since Setting::getValue() is already this cheap
        // and no separate read-before-write query is needed elsewhere (spec 043).
        $changes = [];
        foreach ($settings as $key => $value) {
            $changes[$key] = ['old' => Setting::getValue($key), 'new' => $value];
            Setting::setValue($key, $value);
        }
        $this->auditLogger->record('settings.updated', null, null, $changes);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Configurações salvas com sucesso!'
        ]));
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
            return ApiResponse::error($response, 400, 'INVALID_UPLOAD', 'Envie um arquivo de imagem válido no campo "logo".');
        }

        $allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
        $fileType = $logoFile->getClientMediaType();

        if (!in_array($fileType, $allowedTypes, true)) {
            return ApiResponse::error($response, 400, 'UNSUPPORTED_FILE_TYPE', 'Formato não suportado. Use PNG, JPG ou WebP.');
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
