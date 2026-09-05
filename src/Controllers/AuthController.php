<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
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
            return ApiResponse::error($response, 400, 'MISSING_CREDENTIALS', 'Usuário e senha obrigatórios.');
        }

        $user = User::where('username', $data['username'])->first();
        if (!$user || !password_verify($data['password'], $user->password)) {
            return ApiResponse::error($response, 401, 'INVALID_CREDENTIALS', 'Credenciais inválidas.');
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
            return ApiResponse::error($response, 400, 'MISSING_REQUIRED_FIELDS', 'Senha atual e nova senha são obrigatórias.');
        }

        if (strlen((string) $data['new_password']) < 8) {
            return ApiResponse::error($response, 400, 'PASSWORD_TOO_SHORT', 'A nova senha deve ter ao menos 8 caracteres.');
        }

        $decoded = $request->getAttribute('user');
        $user = User::find($decoded->sub);

        if (!$user || !password_verify($data['current_password'], $user->password)) {
            return ApiResponse::error($response, 401, 'CURRENT_PASSWORD_INCORRECT', 'Senha atual incorreta.');
        }

        $user->password = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $user->save();

        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Senha alterada com sucesso.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}