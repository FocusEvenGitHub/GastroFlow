<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\VersionController;
use App\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Spec 066, AC7 — the production image has no .git, so /version reads the VERSION file
 * written at build time; without one (and without .git) it answers "dev", never an error.
 */
class VersionControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/gf-version-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
    }

    private function version(): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/version');
        $response = (new VersionController(new Settings($this->base)))->current($request, new Response());
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['version'];
    }

    public function testVersionFileWins(): void
    {
        file_put_contents($this->base . '/VERSION', "v1.9.0\n");

        $this->assertSame('v1.9.0', $this->version());
    }

    public function testEmptyVersionFileFallsBackToDev(): void
    {
        // What a build without GASTROFLOW_VERSION leaves behind.
        file_put_contents($this->base . '/VERSION', '');

        $this->assertSame('dev', $this->version());
    }

    public function testNoVersionFileAndNoGitIsDev(): void
    {
        $this->assertSame('dev', $this->version());
    }
}
