<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Models\User;
use App\Validators\AuthValidator;
use Firebase\JWT\JWT;

class AuthController
{
    private string $secret;
    private AuthValidator $validator;

    public function __construct(string $secret, AuthValidator $validator)
    {
        $this->secret = $secret;
        $this->validator = $validator;
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!$this->validator->validateLogin($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
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
        if (!$this->validator->validatePasswordChange($data)) {
            $errors = $this->validator->errors();
            return ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors]);
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