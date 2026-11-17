<?php

declare(strict_types=1);

namespace Tests\Integration;

/** Spec 084: the fiscal status route through the real app — protection and the "off" default. */
class FiscalStatusTest extends IntegrationTestCase
{
    /** @var array<string, ?string> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        // A developer's .env may point at a real NF-F7; these tests are about the default.
        foreach (['NF_F7_URL', 'NF_F7_API_KEY'] as $name) {
            $this->original[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            if ($value !== null) {
                $_ENV[$name] = $value;
            }
        }
        parent::tearDown();
    }

    public function testRequiresAnAdmin(): void
    {
        $this->assertSame(401, $this->request('GET', '/api/admin/fiscal/status')->getStatusCode());
        $this->assertSame(
            403,
            $this->request('GET', '/api/admin/fiscal/status', null, $this->authHeader('manager'))->getStatusCode()
        );
    }

    public function testIsOffByDefault(): void
    {
        $response = $this->request('GET', '/api/admin/fiscal/status', null, $this->authHeader());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'enabled' => false], $this->decode($response));
    }

    public function testAnUnreachableServiceIsAnAnswerNotAnError(): void
    {
        // Port 9 on localhost: refused at once, no real network involved.
        $_ENV['NF_F7_URL'] = 'http://127.0.0.1:9';
        $_ENV['NF_F7_API_KEY'] = 'nff7_integration-test-key';

        $response = $this->request('GET', '/api/admin/fiscal/status', null, $this->authHeader());
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([true, false, 'UNREACHABLE'], [$body['enabled'], $body['connected'], $body['reason']]);
        $this->assertStringNotContainsString('nff7_integration-test-key', (string) $response->getBody());
    }
}
