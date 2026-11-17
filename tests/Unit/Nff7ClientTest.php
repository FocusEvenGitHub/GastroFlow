<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FiscalController;
use App\Services\Nff7Client;
use App\Settings;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Spec 084: the NF-F7 connectivity test, with a fake transport (no network). */
class Nff7ClientTest extends TestCase
{
    private const KEY = 'nff7_unit-test-key-that-must-never-leak';
    private const PONG = '{"client":{"id":1,"name":"gastroflow","issuer_id":3},"version":"0.1.0-gastroflow.1"}';

    /** @var array<string, ?string> */
    private array $original = [];
    private TestHandler $log;
    /** @var list<array{string, list<string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        foreach (['NF_F7_URL', 'NF_F7_API_KEY'] as $name) {
            $this->original[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    private function configure(?string $url = 'http://nff7-app/', ?string $key = self::KEY): void
    {
        $_ENV['NF_F7_URL'] = $url ?? '';
        $_ENV['NF_F7_API_KEY'] = $key ?? '';
    }

    /** @param array{status: int, body: string}|\Throwable $reply */
    private function client(array|\Throwable $reply = ['status' => 200, 'body' => self::PONG]): Nff7Client
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->log);

        return new Nff7Client(new Settings(), $logger, function (string $url, array $headers) use ($reply) {
            $this->calls[] = [$url, $headers];
            return $reply instanceof \Throwable ? throw $reply : $reply;
        });
    }

    private function logText(): string
    {
        return json_encode(array_map(static fn ($record) => $record->toArray(), $this->log->getRecords()));
    }

    public function testOffWhenNothingIsConfiguredAndNoRequestIsMade(): void
    {
        $this->assertSame(['enabled' => false], $this->client()->status());
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function halfConfigured(): array
    {
        return ['only url' => ['http://nff7-app', null], 'only key' => [null, self::KEY], 'blank key' => ['http://nff7-app', '  ']];
    }

    #[DataProvider('halfConfigured')]
    public function testHalfConfiguredIsReportedWithoutARequest(?string $url, ?string $key): void
    {
        $this->configure($url, $key);

        $status = $this->client()->status();

        $this->assertTrue($status['enabled']);
        $this->assertFalse($status['connected']);
        $this->assertSame('INCOMPLETE_CONFIG', $status['reason']);
        $this->assertSame([], $this->calls);
    }

    public function testConnectedReturnsOnlyTheExpectedFieldsAndCallsPingWithBearer(): void
    {
        $this->configure();

        $this->assertSame(
            ['enabled' => true, 'connected' => true, 'client_name' => 'gastroflow', 'issuer_id' => 3, 'version' => '0.1.0-gastroflow.1'],
            $this->client()->status()
        );
        // Trailing slash in NF_F7_URL must not become "//api".
        $this->assertSame('http://nff7-app/api/v1/ping', $this->calls[0][0]);
        $this->assertContains('Authorization: Bearer ' . self::KEY, $this->calls[0][1]);
    }

    /** @return array<string, array{array{status: int, body: string}|\Throwable, string}> */
    public static function failures(): array
    {
        return [
            'key rejected' => [['status' => 401, 'body' => '{"error":{"code":"API_KEY_INVALID"}}'], 'KEY_REJECTED'],
            'transport failure' => [new \RuntimeException('Could not resolve host: ' . self::KEY), 'UNREACHABLE'],
            'server error' => [['status' => 500, 'body' => 'boom ' . self::KEY], 'UNEXPECTED_RESPONSE'],
            'not json' => [['status' => 200, 'body' => '<html>'], 'UNEXPECTED_RESPONSE'],
            'json without the fields' => [['status' => 200, 'body' => '{"client":{"name":"x"}}'], 'UNEXPECTED_RESPONSE'],
            'wrong types' => [['status' => 200, 'body' => '{"client":{"name":["x"],"issuer_id":"3"},"version":1}'], 'UNEXPECTED_RESPONSE'],
        ];
    }

    /** @param array{status: int, body: string}|\Throwable $reply */
    #[DataProvider('failures')]
    public function testFailuresBecomeAReasonAndNeverLeakTheKey(array|\Throwable $reply, string $reason): void
    {
        $this->configure();

        $status = $this->client($reply)->status();

        $this->assertSame([true, false, $reason], [$status['enabled'], $status['connected'], $status['reason']]);
        $this->assertNotSame('', $status['message']);
        $this->assertStringNotContainsString(self::KEY, json_encode($status));
        $this->assertTrue($this->log->hasWarningThatContains('fiscal.ping_failed'));
        $this->assertStringNotContainsString(self::KEY, $this->logText());
    }

    public function testSuccessLogsNothingAndTheControllerAnswers200WithoutTheKey(): void
    {
        $this->configure();

        $response = (new FiscalController($this->client()))->status(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/fiscal/status'),
            (new ResponseFactory())->createResponse()
        );
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($body, true)['success']);
        $this->assertTrue(json_decode($body, true)['connected']);
        $this->assertStringNotContainsString(self::KEY, $body);
        $this->assertSame([], $this->log->getRecords());
    }
}
