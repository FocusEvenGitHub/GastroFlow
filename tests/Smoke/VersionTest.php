<?php

declare(strict_types=1);

namespace Tests\Smoke;

use App\App;
use App\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class VersionTest extends TestCase
{
    public function testGetVersionReturns200WithAVersionString(): void
    {
        $app = (new App(new Settings()))->get();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/version');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertNotEmpty($body['version']);
    }
}
