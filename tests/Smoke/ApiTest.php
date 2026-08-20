<?php

declare(strict_types=1);

namespace Tests\Smoke;

use App\App;
use App\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class ApiTest extends TestCase
{
    public function testGetMenuReturns200(): void
    {
        $app = (new App(new Settings()))->get();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/menu');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
