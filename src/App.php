<?php
namespace App;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class App
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get(): \Slim\App
    {
        // Build container
        $containerBuilder = new ContainerBuilder();
        // Enable autowiring
        $containerBuilder->useAutowiring(true);

        // Add definitions
        $containerBuilder->addDefinitions([
            Settings::class => $this->settings,
            LoggerInterface::class => function () {
                $logger = new Logger('app');
                $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
                return $logger;
            },
        ]);

        $container = $containerBuilder->build();

        // Bootstrap Eloquent
        Database::boot($this->settings);

        // Create Slim App with container
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Middleware
        $app->add(new Middleware\JsonBodyParserMiddleware());
        $app->add(new Middleware\CorsMiddleware());
        $app->addErrorMiddleware(true, true, true);

        // Routes
        (new Routes())->register($app);

        return $app;
    }
}