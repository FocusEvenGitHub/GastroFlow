<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\ApiResponse;
use App\Models\User;
use App\Services\LoginThrottleService;
use App\Validators\AuthValidator;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;

class AuthController
{
    private string $secret;
    private AuthValidator $validator;

    public function __construct(
        string $secret,
        AuthValidator $validator,
        private readonly ?LoginThrottleService $throttle = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
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

        // Login throttling (spec 053): checked before the password, and a blocked
        // attempt is not recorded, so it can't extend its own block. REMOTE_ADDR is
        // set by the server; proxy headers are deliberately not trusted.
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $username = (string) $data['username'];
        $retryAfter = $this->throttle?->check($ip, $username);
        if ($retryAfter !== null) {
            $this->logger?->warning('Login bloqueado por excesso de tentativas', [
                'event'    => 'auth.login_throttled',
                'ip'       => $ip,
                'username' => LoginThrottleService::normalize($username),
            ]);
            $minutes = (int) ceil($retryAfter / 60);
            return ApiResponse::error(
                $response,
                429,
                'TOO_MANY_LOGIN_ATTEMPTS',
                sprintf('Muitas tentativas de login. Tente novamente em %d minuto%s.', $minutes, $minutes === 1 ? '' : 's')
            )->withHeader('Retry-After', (string) $retryAfter);
        }

        $user = User::where('username', $data['username'])->first();
        if (!$user || !password_verify($data['password'], $user->password)) {
            $this->throttle?->recordFailure($ip, $username);
            return ApiResponse::error($response, 401, 'INVALID_CREDENTIALS', 'Credenciais inválidas.');
        }
        $this->throttle?->clear($ip, $username);

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
