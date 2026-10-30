<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SettingsController;
use App\Logging\RequestContext;
use App\Services\AuditLogger;
use App\Services\PricingService;
use App\Services\PrintService;
use App\Settings;
use App\Validators\SettingsValidator;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Spec 065, AC4 — the logo upload stores a real PNG whatever the format sent, and
 * refuses a file GD cannot decode without touching the previous logo.
 */
class SettingsLogoTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not loaded.');
        }
        $this->base = sys_get_temp_dir() . '/gf-upload-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/public/assets/img', 0777, true);
        mkdir($this->base . '/public/uploads', 0777, true);
    }

    private function upload(string $bytes, string $type): Response
    {
        $tmp = $this->base . '/upload.tmp';
        file_put_contents($tmp, $bytes);
        $settings = new Settings($this->base);
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $controller = new SettingsController(
            $settings,
            new SettingsValidator(),
            new AuditLogger(new RequestContext()),
            new PrintService($logger, $settings, new PricingService()),
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/settings/logo')
            ->withUploadedFiles(['logo' => new UploadedFile($tmp, 'logo', $type, strlen($bytes), UPLOAD_ERR_OK)]);

        /** @var Response */
        return $controller->uploadLogo($request, new Response());
    }

    /** Uploads land in public/uploads since spec 066, never over the tracked default. */
    private function logoPath(): string
    {
        return $this->base . '/public/uploads/logo.png';
    }

    private function defaultLogoPath(): string
    {
        return $this->base . '/public/assets/img/logo.png';
    }

    /** Spec 066 — an upload never touches the default logo, and takes over from it. */
    public function testUploadGoesToUploadsAndLeavesTheDefaultLogoAlone(): void
    {
        file_put_contents($this->defaultLogoPath(), 'default logo');
        $settings = new Settings($this->base);
        $this->assertSame($this->defaultLogoPath(), $settings->getLogoPath());
        $this->assertSame('/assets/img/logo.png', $settings->getLogoUrl());

        ob_start();
        imagepng(imagecreatetruecolor(8, 8));
        $response = $this->upload((string) ob_get_clean(), 'image/png');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('default logo', file_get_contents($this->defaultLogoPath()));
        $this->assertSame($this->logoPath(), $settings->getLogoPath());
        $this->assertSame('/uploads/logo.png', $settings->getLogoUrl());
    }

    public function testNoLogoAtAllHasNoUrl(): void
    {
        $this->assertNull((new Settings($this->base))->getLogoUrl());
    }

    public function testJpegAndWebpAreStoredAsPng(): void
    {
        foreach (['imagejpeg' => 'image/jpeg', 'imagewebp' => 'image/webp'] as $encoder => $type) {
            ob_start();
            $encoder(imagecreatetruecolor(8, 8));
            $response = $this->upload((string) ob_get_clean(), $type);

            $this->assertSame(200, $response->getStatusCode(), $type);
            $this->assertSame("\x89PNG", substr((string) file_get_contents($this->logoPath()), 0, 4), $type);
        }
    }

    public function testUndecodableFileIsRejectedAndThePreviousLogoKept(): void
    {
        file_put_contents($this->logoPath(), 'previous logo');

        $response = $this->upload('this is text, not an image', 'image/png');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('INVALID_IMAGE', (string) $response->getBody());
        $this->assertSame('previous logo', file_get_contents($this->logoPath()));
    }
}
