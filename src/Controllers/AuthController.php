<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use Firebase\JWT\JWT;

class AuthController
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!isset($data['username'], $data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Usuário e senha obrigatórios.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = User::where('username', $data['username'])->first();
        if (!$user || !password_verify($data['password'], $user->password)) {
            $response->getBody()->write(json_encode(['error' => 'Credenciais inválidas.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $payload = [
            'sub' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + 3600 * 8, // 8 horas
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        $response->getBody()->write(json_encode([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}