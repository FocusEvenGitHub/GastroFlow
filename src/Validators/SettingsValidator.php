<?php

declare(strict_types=1);

namespace App\Validators;

use Valitron\Validator;

/**
 * Shape validation for admin settings updates (docs/ROADMAP.md's v1.7.0
 * "Input validation" — spec 027).
 *
 * Every value must be a string or null: Setting::setValue(string $key,
 * ?string $value) is called from SettingsController.php (AdminController
 * before spec 028's split), which has declare(strict_types=1) — passing
 * anything else (int/float/bool/array) already throws an uncaught TypeError
 * today. This applies to every key, not only the three known ones below, so a
 * bad-shape value now gets a clean 400 instead of a 500 (spec 027's
 * Implementation log).
 *
 * The Setting model is a deliberately free-form key-value store (not every
 * key is enumerated here) — an unrecognized key still passes through to
 * Setting::setValue() once it passes the string-or-null check.
 */
class SettingsValidator
{
    private Validator $v;

    public function validate(array $settings): bool
    {
        $this->v = new Validator($settings);

        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $this->v->rule(function ($field, $val) {
                return $val === null || is_string($val);
            }, $key)->message('{field} deve ser um texto');
        }

        // Shape rules for the settings actually read anywhere in the app
        // (confirmed via grep across src/ — Setting::getValue()/setValue()
        // callers): restaurant_name, printer_ip, printer_port.
        if (array_key_exists('restaurant_name', $settings)) {
            $this->v->rule('lengthMax', 'restaurant_name', 100);
        }
        if (array_key_exists('printer_ip', $settings)) {
            $this->v->rule('lengthMax', 'printer_ip', 45); // fits an IPv4 or hostname
        }
        if (array_key_exists('printer_port', $settings)) {
            $this->v->rule('numeric', 'printer_port');
            $this->v->rule('integer', 'printer_port');
            $this->v->rule('min', 'printer_port', 1);
            $this->v->rule('max', 'printer_port', 65535);
        }

        return $this->v->validate();
    }

    public function errors(): array
    {
        return $this->v->errors();
    }
}
