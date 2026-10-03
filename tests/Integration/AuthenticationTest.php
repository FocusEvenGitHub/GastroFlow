<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Authentication and authorization against real MySQL (spec 035, AC4 and AC5).
 *
 * No user is seeded by the schema (spec 015 removed the default admin), so each test
 * creates its own through the same bcrypt path bin/create-admin uses.
 */
class AuthenticationTest extends IntegrationTestCase
{
    /** AC4 */
    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $user = $this->createUser('admin');

        $response = $this->request('POST', '/api/login', [
            'username' => $user['username'],
            'password' => $user['password'],
        ]);

        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('token', $body);
        $this->assertNotSame('', $body['token']);
        $this->assertSame('admin', $body['user']['role']);
    }

    /** AC4 */
    public function testLoginFailsWithWrongPassword(): void
    {
        $user = $this->createUser('admin');

        $response = $this->request('POST', '/api/login', [
            'username' => $user['username'],
            'password' => $user['password'] . '-wrong',
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testLoginFailsForUnknownUser(): void
    {
        $response = $this->request('POST', '/api/login', [
            'username' => 'nobody_' . bin2hex(random_bytes(3)),
            'password' => 'irrelevant',
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    /** Spec 053, AC5 — the 11th attempt is throttled even with the right password. */
    public function testLoginIsThrottledAfterTenFailures(): void
    {
        $user = $this->createUser('admin');
        $other = $this->createUser('admin');

        // A validation error (400) does not count as a failure.
        $this->assertSame(400, $this->request('POST', '/api/login', ['username' => $user['username']])->getStatusCode());

        for ($i = 0; $i < 10; $i++) {
            $response = $this->request('POST', '/api/login', [
                'username' => $user['username'],
                'password' => 'wrong-' . $i,
            ]);
            $this->assertSame(401, $response->getStatusCode(), "attempt {$i}");
        }

        $blocked = $this->request('POST', '/api/login', [
            'username' => $user['username'],
            'password' => $user['password'],
        ]);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('TOO_MANY_LOGIN_ATTEMPTS', $this->decode($blocked)['code']);
        $this->assertGreaterThan(0, (int) $blocked->getHeaderLine('Retry-After'));

        // Another user from the same (test) IP, under the per-IP limit, still logs in.
        $ok = $this->request('POST', '/api/login', [
            'username' => $other['username'],
            'password' => $other['password'],
        ]);
        $this->assertSame(200, $ok->getStatusCode());
    }

    /** AC5 */
    public function testAdminRouteRejectsMissingToken(): void
    {
        $response = $this->request('GET', '/api/admin/reports/sales');

        $this->assertSame(401, $response->getStatusCode());
    }

    /** AC5 */
    public function testAdminRouteRejectsMalformedToken(): void
    {
        $response = $this->request('GET', '/api/admin/reports/sales', null, [
            'Authorization' => 'Bearer not-a-real-token',
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    /** AC5 — a valid token with the wrong role must be refused, not accepted. */
    public function testReportsRefuseACashierToken(): void
    {
        $response = $this->request('GET', '/api/admin/reports/sales', null, $this->authHeader('cashier'));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReportsAcceptAManagerToken(): void
    {
        $response = $this->request('GET', '/api/admin/reports/sales', null, $this->authHeader('manager'));

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Settings are admin-only, unlike reports — proves the two role sets differ. */
    public function testSettingsRefuseAManagerToken(): void
    {
        $response = $this->request('GET', '/api/admin/settings', null, $this->authHeader('manager'));

        $this->assertSame(403, $response->getStatusCode());
    }
}
