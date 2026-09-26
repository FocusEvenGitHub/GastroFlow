<?php

declare(strict_types=1);

namespace Tests\Smoke;

use App\App;
use App\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class HealthTest extends TestCase
{
    public function testHealthLiveReturns200(): void
    {
        $app = (new App(new Settings()))->get();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health/live');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['success' => true, 'status' => 'live'], $body);
    }

    public function testHealthReadyReturns200WhenDatabaseIsReachable(): void
    {
        $app = (new App(new Settings()))->get();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health/ready');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(['success' => true, 'status' => 'ready'], $body);
    }

    public function testHealthEndpointsRequireNoAuthentication(): void
    {
        $app = (new App(new Settings()))->get();

        foreach (['/health/live', '/health/ready'] as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
            $response = $app->handle($request);

            $this->assertNotSame(401, $response->getStatusCode());
        }
    }
}
