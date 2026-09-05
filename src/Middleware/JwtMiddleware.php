<?php
declare(strict_types=1);

namespace App\Middleware;

use App\ApiResponse;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class JwtMiddleware implements MiddlewareInterface
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return ApiResponse::error(new Response(), 401, 'TOKEN_MISSING', 'Token não fornecido.');
        }

        $token = $matches[1];
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            // Adiciona os dados do usuário ao request para uso posterior
            $request = $request->withAttribute('user', $decoded);
        } catch (ExpiredException $e) {
            return ApiResponse::error(new Response(), 401, 'TOKEN_EXPIRED', 'Token expirado.');
        } catch (\Throwable $e) {
            return ApiResponse::error(new Response(), 401, 'TOKEN_INVALID', 'Token inválido.');
        }

        return $handler->handle($request);
    }
}