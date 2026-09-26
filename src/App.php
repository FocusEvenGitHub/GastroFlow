<?php

declare(strict_types=1);

namespace App;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\ServerRequestInterface;
use App\Services\EventPublisher;
use App\Services\DatabaseEventPublisher;
use App\Logging\RequestContext;
use App\Logging\RequestIdProcessor;
use App\Middleware\CorrelationIdMiddleware;
use App\Services\JobService;
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
            LoggerInterface::class => function (RequestContext $context) {
                $logger = new Logger('app');
                $logger->pushHandler(new StreamHandler($this->settings->getLogFile(), Logger::DEBUG));
                // Attaches request_id/user_id to every record automatically (spec 042) — no
                // log call site elsewhere needs to pass them explicitly.
                $logger->pushProcessor(new RequestIdProcessor($context));
                return $logger;
            },
            // Binds the roadmap's named abstraction (OrderService -> EventPublisher -> ...)
            // to its MySQL-backed implementation (spec 041). Autowiring alone can't resolve
            // an interface without this — PHP-DI needs an explicit binding.
            EventPublisher::class => \DI\autowire(DatabaseEventPublisher::class),
            // Verified empirically (spec 042): PHP-DI's autowiring never resolves a
            // constructor parameter that has a default value — it just uses the default,
            // regardless of the type hint or nullability. JobService's $requestContext is
            // optional (defaults to null) so `new JobService()` keeps working in bin/worker,
            // which means plain autowiring alone would silently leave it null in the HTTP
            // path too. constructorParameter() overrides just that one parameter's
            // resolution without touching $logger/$settings, which already resolve
            // correctly via their own explicit bindings above.
            JobService::class => \DI\autowire(JobService::class)
                ->constructorParameter('requestContext', \DI\get(RequestContext::class)),
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
        // Added last among these three (still inside the error middleware below) so it's
        // outermost of the three — Slim's middleware stack is last-in-first-out (spec 042).
        // No scalar constructor args, so a class-string lets Slim resolve it via the
        // container (autowired), same as controllers registered as [Class::class, 'method'].
        $app->add(CorrelationIdMiddleware::class);

        // Error middleware — always return JSON for API routes
        $settings = $this->settings;
        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
        $errorMiddleware->setDefaultErrorHandler(function (
            ServerRequestInterface $request,
            Throwable $exception,
            bool $displayErrorDetails
        ) use ($app, $container, $settings) {
            $isHttpSpecialized = $exception instanceof \Slim\Exception\HttpSpecializedException;
            $statusCode = $isHttpSpecialized ? $exception->getCode() : 500;
            $debug = $settings->isDebug();

            // Log the error
            try {
                $logger = $container->get(LoggerInterface::class);
                $context = [
                    'method'  => $request->getMethod(),
                    'path'    => (string)$request->getUri(),
                    'status'  => $statusCode,
                ];
                if ($debug) {
                    $context['trace'] = $exception->getTraceAsString();
                }
                $logger->error($exception->getMessage(), $context);
            } catch (\Throwable $logErr) {
                // Silently ignore logger failures
            }

            $response = $app->getResponseFactory()->createResponse($statusCode);

            // This response is built fresh here, bypassing CorrelationIdMiddleware's own
            // return path — echo the same request_id by hand so an error response still
            // carries it (spec 042).
            $requestId = $container->get(RequestContext::class)->getRequestId();
            if ($requestId !== null) {
                $response = $response->withHeader(CorrelationIdMiddleware::HEADER_NAME, $requestId);
            }

            // Slim's own HTTP exception messages (e.g. "Not Found") never leak internals,
            // so they're shown regardless of debug mode; only an unrecognized/500 error
            // is sanitized when not in debug mode.
            if (!$debug && !$isHttpSpecialized) {
                $payload = [
                    'success' => false,
                    'error'   => 'Erro interno do servidor.',
                    'code'    => 'INTERNAL_ERROR',
                ];
            } else {
                $payload = ['error' => $exception->getMessage()];
                if ($debug) {
                    $payload['file']  = $exception->getFile();
                    $payload['line']  = $exception->getLine();
                    $payload['trace'] = explode("\n", $exception->getTraceAsString());
                }
            }
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Routes
        (new Routes())->register($app);

        return $app;
    }
}
