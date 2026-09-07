<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\AuthValidator;
use PHPUnit\Framework\TestCase;

class AuthValidatorTest extends TestCase
{
    public function testValidLoginPasses(): void
    {
        $validator = new AuthValidator();

        $this->assertTrue($validator->validateLogin(['username' => 'admin', 'password' => 'secret']));
    }

    public function testLoginMissingFieldsFails(): void
    {
        $validator = new AuthValidator();

        $this->assertFalse($validator->validateLogin([]));
        $this->assertFalse($validator->validateLogin(['username' => 'admin']));
        $this->assertFalse($validator->validateLogin(['password' => 'secret']));
    }

    public function testLoginWithNonStringUsernameFails(): void
    {
        // Regression: previously reached User::where('username', [...])
        // unguarded and raised an uncaught TypeError (a 500).
        $validator = new AuthValidator();

        $this->assertFalse($validator->validateLogin(['username' => ['a'], 'password' => 'secret']));
    }

    public function testLoginWithNonStringPasswordFails(): void
    {
        $validator = new AuthValidator();

        $this->assertFalse($validator->validateLogin(['username' => 'admin', 'password' => ['a']]));
    }

    public function testLoginWithUsernameOverLimitFails(): void
    {
        $validator = new AuthValidator();

        $result = $validator->validateLogin([
            'username' => str_repeat('a', 51),
            'password' => 'secret',
        ]);

        $this->assertFalse($result);
    }

    public function testValidPasswordChangePasses(): void
    {
        $validator = new AuthValidator();

        $result = $validator->validatePasswordChange([
            'current_password' => 'oldpass',
            'new_password' => 'newpassword',
        ]);

        $this->assertTrue($result);
    }

    public function testPasswordChangeMissingFieldsFails(): void
    {
        $validator = new AuthValidator();

        $this->assertFalse($validator->validatePasswordChange([]));
    }

    public function testPasswordChangeWithShortNewPasswordFails(): void
    {
        $validator = new AuthValidator();

        $result = $validator->validatePasswordChange([
            'current_password' => 'oldpass',
            'new_password' => 'short',
        ]);

        $this->assertFalse($result);
    }

    public function testPasswordChangeWithNonStringNewPasswordFails(): void
    {
        $validator = new AuthValidator();

        $result = $validator->validatePasswordChange([
            'current_password' => 'oldpass',
            'new_password' => 12345678,
        ]);

        $this->assertFalse($result);
    }
}
