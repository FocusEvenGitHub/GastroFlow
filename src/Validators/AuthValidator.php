<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

/**
 * Shape validation for login/password-change (docs/ROADMAP.md's v1.7.0
 * "Input validation" — spec 027). Closes a real gap: AuthController
 * previously checked field presence (isset()) but not type, so a non-string
 * value reached User::where()/password_verify() unguarded and raised an
 * uncaught TypeError (a 500) instead of a clean 400.
 */
class AuthValidator
{
    private Validator $v;

    public function validateLogin(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', ['username', 'password']);
        $this->v->rule(function ($field, $value) {
            return is_string($value);
        }, 'username')->message('{field} deve ser um texto');
        $this->v->rule('lengthMax', 'username', 50); // users.username VARCHAR(50)
        $this->v->rule(function ($field, $value) {
            return is_string($value);
        }, 'password')->message('{field} deve ser um texto');
        return $this->v->validate();
    }

    public function validatePasswordChange(array $data): bool
    {
        $this->v = new Validator($data);
        $this->v->rule('required', ['current_password', 'new_password']);
        $this->v->rule(function ($field, $value) {
            return is_string($value);
        }, 'current_password')->message('{field} deve ser um texto');
        $this->v->rule(function ($field, $value) {
            return is_string($value);
        }, 'new_password')->message('{field} deve ser um texto');
        // Preserves changePassword()'s existing minimum-length rule.
        // Valitron's lengthMin safely fails (not a crash) on a non-string
        // value, so this can run unconditionally alongside the type check
        // above.
        $this->v->rule('lengthMin', 'new_password', 8);
        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}
