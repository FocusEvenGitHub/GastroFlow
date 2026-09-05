<?php
declare(strict_types=1);

namespace App\Middleware;

use App\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class RoleMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (!$user || !isset($user->role) || !in_array($user->role, $this->allowedRoles, true)) {
            return ApiResponse::error(new Response(), 403, 'FORBIDDEN', 'Acesso negado.');
        }

        return $handler->handle($request);
    }
}
