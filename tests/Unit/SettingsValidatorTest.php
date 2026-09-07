<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validators\SettingsValidator;
use PHPUnit\Framework\TestCase;

class SettingsValidatorTest extends TestCase
{
    public function testValidKnownSettingsPass(): void
    {
        $validator = new SettingsValidator();

        $result = $validator->validate([
            'restaurant_name' => 'GastroFlow',
            'printer_ip' => '192.168.0.100',
            'printer_port' => '9100',
        ]);

        $this->assertTrue($result);
    }

    public function testNonNumericPrinterPortFails(): void
    {
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['printer_port' => 'abc']));
    }

    public function testPrinterPortOutOfRangeFails(): void
    {
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['printer_port' => '99999']));
        $this->assertFalse($validator->validate(['printer_port' => '0']));
    }

    public function testRestaurantNameOverLimitFails(): void
    {
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['restaurant_name' => str_repeat('a', 101)]));
    }

    public function testPrinterIpOverLimitFails(): void
    {
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['printer_ip' => str_repeat('a', 46)]));
    }

    public function testNonStringValueForKnownKeyFails(): void
    {
        // Setting::setValue(string $key, ?string $value) is called from a
        // strict_types=1 file — a non-string value already throws a raw
        // TypeError today. This must be rejected with a clean 400 instead.
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['printer_port' => 9100]));
    }

    public function testNonStringValueForUnknownKeyFails(): void
    {
        // Unknown keys still pass through to Setting::setValue() unchanged
        // (the store is deliberately schema-less), but must still be a
        // string or null for the same TypeError reason.
        $validator = new SettingsValidator();

        $this->assertFalse($validator->validate(['some_future_setting' => ['nested' => 'value']]));
    }

    public function testUnknownStringKeyPasses(): void
    {
        $validator = new SettingsValidator();

        $this->assertTrue($validator->validate(['some_future_setting' => 'ok']));
    }

    public function testNullValueForKnownKeyPasses(): void
    {
        $validator = new SettingsValidator();

        $this->assertTrue($validator->validate(['printer_ip' => null]));
    }
}
