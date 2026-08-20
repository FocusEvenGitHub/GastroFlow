<?php
namespace App;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

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
                $logger->pushHandler(new StreamHandler($this->settings->getLogFile(), Logger::DEBUG));
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
        $app->add(new Middleware\CorsMiddleware($this->settings->get('CORS_ALLOWED_ORIGIN', '*')));

        // Error middleware — always return JSON for API routes
        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
        $errorMiddleware->setDefaultErrorHandler(function (
            ServerRequestInterface $request,
            Throwable $exception,
            bool $displayErrorDetails
        ) use ($app, $container) {
            $statusCode = 500;
            if ($exception instanceof \Slim\Exception\HttpSpecializedException) {
                $statusCode = $exception->getCode();
            }

            // Log the error
            try {
                $logger = $container->get(LoggerInterface::class);
                $context = [
                    'method'  => $request->getMethod(),
                    'path'    => (string)$request->getUri(),
                    'status'  => $statusCode,
                ];
                if ($displayErrorDetails) {
                    $context['trace'] = $exception->getTraceAsString();
                }
                $logger->error($exception->getMessage(), $context);
            } catch (\Throwable $logErr) {
                // Silently ignore logger failures
            }

            $response = $app->getResponseFactory()->createResponse($statusCode);
            $payload = [
                'error' => $displayErrorDetails
                    ? $exception->getMessage()
                    : 'Erro interno do servidor.',
            ];
            if ($displayErrorDetails) {
                $payload['file']  = $exception->getFile();
                $payload['line']  = $exception->getLine();
                $payload['trace'] = explode("\n", $exception->getTraceAsString());
            }
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Routes
        (new Routes())->register($app);

        return $app;
    }
}