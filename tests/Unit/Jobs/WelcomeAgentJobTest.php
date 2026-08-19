<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WelcomeAgentJobTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal';

    private const TOKEN_URI = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal/protocol/openid-connect/token';

    private const USER_ID = 'kc-user-1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    #[Test]
    public function sets_keycloak_password_when_staff_flag_is_on(): void
    {
        config([
            'keycloak.staff.enabled' => true,
            'keycloak.staff.issuer' => self::ISSUER,
            'keycloak.staff.token_uri' => self::TOKEN_URI,
            'keycloak.staff.admin_client_id' => 'backoffice-staff-admin',
            'keycloak.staff.admin_client_secret' => 'admin-secret',
        ]);
        Bus::fake([SendEmailNotificationJob::class]);
        $this->fakeAdminApi();

        $user = User::factory()->create([
            'email' => 'ada.koto@example.com',
            'name' => 'KOTO',
            'first_name' => 'Ada',
        ]);

        (new WelcomeAgentJob($user))->handle();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT' || ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/reset-password')) {
                return false;
            }

            $password = $request['value'] ?? null;

            return $request['temporary'] === false
                && is_string($password)
                && strlen($password) === 16
                && (bool) preg_match('/[A-Z]/', $password)
                && (bool) preg_match('/[a-z]/', $password)
                && (bool) preg_match('/[0-9]/', $password)
                && (bool) preg_match('/[!@#$%&*\-_]/', $password);
        });
        Bus::assertDispatched(SendEmailNotificationJob::class);
    }

    #[Test]
    public function does_not_call_keycloak_when_staff_flag_is_off(): void
    {
        config(['keycloak.staff.enabled' => false]);
        Bus::fake([SendEmailNotificationJob::class]);
        Http::fake();

        $user = User::factory()->create([
            'email' => 'ada.koto@example.com',
            'name' => 'KOTO',
            'first_name' => 'Ada',
        ]);

        (new WelcomeAgentJob($user))->handle();

        Http::assertNothingSent();
        Bus::assertDispatched(SendEmailNotificationJob::class);
    }

    private function fakeAdminApi(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $path = is_string($path) ? $path : '';
            $method = $request->method();

            if ($method === 'POST' && str_ends_with($path, '/protocol/openid-connect/token')) {
                return Http::response(['access_token' => 'admin-token', 'expires_in' => 300]);
            }

            if ($method === 'GET' && $path === '/admin/realms/pki-portal/users') {
                return Http::response([[
                    'id' => self::USER_ID,
                    'email' => 'ada.koto@example.com',
                ]]);
            }

            if ($method === 'PUT' && str_ends_with($path, '/reset-password')) {
                return Http::response([], 204);
            }

            return Http::response(['error' => 'unmocked '.$method.' '.$path], 599);
        });
    }
}
