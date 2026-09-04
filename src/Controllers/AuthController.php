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

    public function changePassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!isset($data['current_password'], $data['new_password'])) {
            $response->getBody()->write(json_encode(['error' => 'Senha atual e nova senha são obrigatórias.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (strlen((string) $data['new_password']) < 8) {
            $response->getBody()->write(json_encode(['error' => 'A nova senha deve ter ao menos 8 caracteres.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $decoded = $request->getAttribute('user');
        $user = User::find($decoded->sub);

        if (!$user || !password_verify($data['current_password'], $user->password)) {
            $response->getBody()->write(json_encode(['error' => 'Senha atual incorreta.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $user->password = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $user->save();

        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Senha alterada com sucesso.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}