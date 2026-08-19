<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Exceptions\StaffKeycloakAdminException;
use App\Services\Auth\StaffKeycloakAdminClient;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StaffKeycloakAdminClientTest extends TestCase
{
    private const ISSUER = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal';

    private const TOKEN_URI = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal/protocol/openid-connect/token';

    private const ADMIN_BASE = 'https://demo-oauth.qcdigitalhub.com/admin/realms/pki-portal';

    private const USER_ID = 'kc-user-1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'keycloak.log_calls' => false,
            'keycloak.staff.enabled' => true,
            'keycloak.staff.issuer' => self::ISSUER,
            'keycloak.staff.token_uri' => self::TOKEN_URI,
            'keycloak.staff.admin_client_id' => 'backoffice-staff-admin',
            'keycloak.staff.admin_client_secret' => 'admin-secret-must-not-appear',
            'keycloak.staff.client_id' => 'backoffice-stranger',
            'keycloak.staff.actions_redirect_uri' => 'http://localhost:4200',
        ]);
    }

    #[Test]
    public function assert_ready_fails_when_secret_is_missing(): void
    {
        config(['keycloak.staff.admin_client_secret' => null]);

        try {
            (new StaffKeycloakAdminClient)->assertReady();
            $this->fail('Expected StaffKeycloakAdminException');
        } catch (StaffKeycloakAdminException $e) {
            $this->assertSame(503, $e->status());
        }
    }

    #[Test]
    public function sync_user_creates_and_assigns_realm_role(): void
    {
        $this->fakeAdminApi();

        $id = (new StaffKeycloakAdminClient)->syncUser('ada.koto@example.com', [
            'email' => 'ada.koto@example.com',
            'first_name' => 'Ada',
            'name' => 'KOTO',
            'role' => 'agent',
        ]);

        $this->assertSame(self::USER_ID, $id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $this->path($request) === '/admin/realms/pki-portal/users'
            && $request['email'] === 'ada.koto@example.com'
            && $request['requiredActions'] === ['UPDATE_PASSWORD']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($this->path($request), '/role-mappings/realm')
            && ($request->data()[0]['name'] ?? null) === 'agent');
    }

    #[Test]
    public function sync_user_updates_existing_and_replaces_staff_role(): void
    {
        $this->fakeAdminApi(existing: true, currentStaffRole: 'agent');

        (new StaffKeycloakAdminClient)->syncUser('ada.koto@example.com', [
            'email' => 'ada.koto@example.com',
            'first_name' => 'Ada',
            'name' => 'KOTO',
            'role' => 'manager',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $this->path($request) === '/admin/realms/pki-portal/users/'.self::USER_ID);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($this->path($request), '/role-mappings/realm'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($this->path($request), '/role-mappings/realm')
            && ($request->data()[0]['name'] ?? null) === 'manager');
    }

    #[Test]
    public function delete_by_email_treats_missing_user_as_success(): void
    {
        $this->fakeAdminApi(existing: false);

        (new StaffKeycloakAdminClient)->deleteByEmail('ghost@example.com');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($this->path($request), '/users/'));
    }

    #[Test]
    public function send_update_password_email_does_not_throw_on_failure(): void
    {
        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        $this->fakeAdminApi(executeActionsStatus: 500);

        (new StaffKeycloakAdminClient)->sendUpdatePasswordEmail(self::USER_ID);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($this->path($request), 'execute-actions-email')
            && str_contains($request->url(), 'client_id=backoffice-stranger')
            && str_contains($request->url(), 'redirect_uri='.rawurlencode('http://localhost:4200')));

        $warning = collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->level === 'warning'
                && str_contains($log->message, 'execute-actions-email')
        );
        $this->assertNotNull($warning);
        $this->assertSame(500, $warning->context['status'] ?? null);
        $this->assertSame('Failed to send execute actions email', $warning->context['error'] ?? null);
    }

    #[Test]
    public function set_password_by_email_calls_reset_password(): void
    {
        $this->fakeAdminApi(existing: true);

        (new StaffKeycloakAdminClient)->setPasswordByEmail('ada.koto@example.com', 'TempPass1!');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($this->path($request), '/reset-password')
            && $request['value'] === 'TempPass1!'
            && $request['temporary'] === false);
    }

    #[Test]
    public function admin_calls_do_not_log_secrets(): void
    {
        config(['keycloak.log_calls' => true]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        $this->fakeAdminApi();
        (new StaffKeycloakAdminClient)->syncUser('ada.koto@example.com', [
            'email' => 'ada.koto@example.com',
            'first_name' => 'Ada',
            'name' => 'KOTO',
            'role' => 'agent',
        ]);

        $this->assertNotEmpty($recorded);
        foreach ($recorded as $log) {
            $encoded = json_encode($log->context, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('admin-secret-must-not-appear', $encoded);
            $this->assertStringNotContainsString('admin-token', $encoded);
            $this->assertArrayNotHasKey('access_token', $log->context);
            $this->assertArrayNotHasKey('client_secret', $log->context);
        }
    }

    private function fakeAdminApi(bool $existing = false, ?string $currentStaffRole = null, int $executeActionsStatus = 204): void
    {
        Http::fake(function (Request $request) use ($existing, $currentStaffRole, $executeActionsStatus) {
            $path = $this->path($request);
            $method = $request->method();

            if ($method === 'POST' && str_ends_with($path, '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            if ($method === 'GET' && $path === '/admin/realms/pki-portal/users') {
                if (! $existing) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => self::USER_ID,
                    'email' => 'ada.koto@example.com',
                ]]);
            }

            if ($method === 'POST' && $path === '/admin/realms/pki-portal/users') {
                return Http::response([], 201, [
                    'Location' => self::ADMIN_BASE.'/users/'.self::USER_ID,
                ]);
            }

            if ($method === 'PUT' && $path === '/admin/realms/pki-portal/users/'.self::USER_ID) {
                return Http::response([], 204);
            }

            if ($method === 'GET' && str_starts_with($path, '/admin/realms/pki-portal/roles/')) {
                $name = basename($path);

                return Http::response(['id' => 'role-'.$name, 'name' => $name]);
            }

            if ($method === 'GET' && str_ends_with($path, '/role-mappings/realm')) {
                if ($currentStaffRole === null) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id' => 'role-'.$currentStaffRole,
                    'name' => $currentStaffRole,
                ]]);
            }

            if (in_array($method, ['POST', 'DELETE'], true) && str_ends_with($path, '/role-mappings/realm')) {
                return Http::response([], 204);
            }

            if ($method === 'PUT' && str_ends_with($path, '/execute-actions-email')) {
                return Http::response(
                    ['error_description' => 'Failed to send execute actions email'],
                    $executeActionsStatus
                );
            }

            if ($method === 'PUT' && str_ends_with($path, '/reset-password')) {
                return Http::response([], 204);
            }

            if ($method === 'DELETE' && $path === '/admin/realms/pki-portal/users/'.self::USER_ID) {
                return Http::response([], 204);
            }

            return Http::response(['error' => 'unmocked '.$method.' '.$path], 599);
        });
    }

    private function path(Request $request): string
    {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
