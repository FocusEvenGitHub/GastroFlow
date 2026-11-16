<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Routes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

/** Spec 082: an empty JWT_SECRET is refused like a missing one. */
class RoutesJwtSecretTest extends TestCase
{
    private ?string $original;

    protected function setUp(): void
    {
        $this->original = $_ENV['JWT_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->original === null) {
            unset($_ENV['JWT_SECRET']);
        } else {
            $_ENV['JWT_SECRET'] = $this->original;
        }
    }

    /** @return array<string, array{?string}> */
    public static function unusableSecrets(): array
    {
        return ['missing' => [null], 'empty' => [''], 'only spaces' => ['   ']];
    }

    #[DataProvider('unusableSecrets')]
    public function testRegisterRefusesAnUnusableSecret(?string $secret): void
    {
        if ($secret === null) {
            unset($_ENV['JWT_SECRET']);
        } else {
            $_ENV['JWT_SECRET'] = $secret;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET environment variable is not set.');

        (new Routes())->register(AppFactory::create());
    }

    public function testRegisterAcceptsANonEmptySecret(): void
    {
        $_ENV['JWT_SECRET'] = 'unit-test-secret-not-for-production';

        $app = AppFactory::create();
        (new Routes())->register($app);

        $this->assertNotEmpty($app->getRouteCollector()->getRoutes());
    }
}
