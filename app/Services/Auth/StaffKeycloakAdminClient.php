<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\StaffKeycloakAdminException;
use App\Support\KeycloakCallLogger;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class StaffKeycloakAdminClient
{
    private const TOKEN_CACHE_KEY = 'keycloak.staff.admin_token';

    public function assertReady(): void
    {
        $secret = config('keycloak.staff.admin_client_secret');
        if (! is_string($secret) || $secret === '') {
            throw new StaffKeycloakAdminException('Client admin Keycloak non configuré.', 503);
        }

        $this->issuer();
        $this->adminBase();
    }

    /**
     * Create or update the Keycloak user and replace staff realm roles.
     *
     * @param  array{email: string, first_name: ?string, name: string, role: string, password?: ?string}  $attrs
     */
    public function syncUser(string $lookupEmail, array $attrs): string
    {
        $this->assertReady();

        $email = strtolower(trim($attrs['email']));
        $lookupEmail = strtolower(trim($lookupEmail));

        $id = $this->findUserId($lookupEmail);
        if ($id === null && $lookupEmail !== $email) {
            $id = $this->findUserId($email);
        }

        $password = $attrs['password'] ?? null;
        $hasPassword = is_string($password) && $password !== '';

        $body = [
            'username' => $email,
            'email' => $email,
            'firstName' => (string) ($attrs['first_name'] ?? ''),
            'lastName' => $attrs['name'],
            'enabled' => true,
            'emailVerified' => true,
        ];
        if (! $hasPassword) {
            $body['requiredActions'] = ['UPDATE_PASSWORD'];
        }

        if ($id === null) {
            $id = $this->createUser($body, $email);
        } else {
            $this->updateUser($id, $body);
        }

        $this->replaceStaffRealmRole($id, $attrs['role']);

        if (is_string($password) && $password !== '') {
            $this->setPassword($id, $password);
        }

        return $id;
    }

    public function deleteByEmail(string $email): void
    {
        $this->assertReady();

        $id = $this->findUserId(strtolower(trim($email)));
        if ($id === null) {
            return;
        }

        $url = $this->adminBase().'/users/'.$id;
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->delete($url);
        KeycloakCallLogger::keycloak('admin_delete_user', 'DELETE', $url, $response->status());

        if ($response->status() === 404) {
            return;
        }

        $this->ensureOk($response, 'Suppression utilisateur Keycloak impossible.');
    }

    public function sendUpdatePasswordEmail(string $keycloakUserId): void
    {
        try {
            $url = $this->adminBase().'/users/'.$keycloakUserId.'/execute-actions-email';
            $response = Http::withToken($this->token())->acceptJson()->timeout(10)
                ->withBody(json_encode(['UPDATE_PASSWORD'], JSON_THROW_ON_ERROR), 'application/json')
                ->put($url);
            KeycloakCallLogger::keycloak('admin_execute_actions_email', 'PUT', $url, $response->status());

            if ($response->failed()) {
                Log::warning('Keycloak execute-actions-email failed.', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Keycloak execute-actions-email failed: '.$e->getMessage());
        }
    }

    private function findUserId(string $email): ?string
    {
        $url = $this->adminBase().'/users';
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->get($url, [
            'email' => $email,
            'exact' => 'true',
        ]);
        KeycloakCallLogger::keycloak('admin_find_user', 'GET', $url, $response->status(), [
            'email_present' => $email !== '',
        ]);
        $this->ensureOk($response, 'Recherche utilisateur Keycloak impossible.');

        $users = $response->json();
        if (! is_array($users) || $users === []) {
            return null;
        }

        $first = $users[0] ?? null;
        if (! is_array($first) || ! is_string($first['id'] ?? null) || $first['id'] === '') {
            return null;
        }

        return $first['id'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function createUser(array $body, string $email): string
    {
        $url = $this->adminBase().'/users';
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->post($url, $body);
        KeycloakCallLogger::keycloak('admin_create_user', 'POST', $url, $response->status());

        if ($response->status() === 409) {
            $existing = $this->findUserId($email);
            if (is_string($existing)) {
                $this->updateUser($existing, $body);

                return $existing;
            }
        }

        $this->ensureCreated($response, 'Création utilisateur Keycloak impossible.');

        $id = $this->idFromLocation($response->header('Location'));
        if ($id !== null) {
            return $id;
        }

        $existing = $this->findUserId($email);
        if (is_string($existing)) {
            return $existing;
        }

        throw new StaffKeycloakAdminException('Création utilisateur Keycloak : identifiant manquant.', 502);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function updateUser(string $id, array $body): void
    {
        $url = $this->adminBase().'/users/'.$id;
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->put($url, $body);
        KeycloakCallLogger::keycloak('admin_update_user', 'PUT', $url, $response->status());
        $this->ensureOk($response, 'Mise à jour utilisateur Keycloak impossible.');
    }

    private function setPassword(string $id, string $password): void
    {
        $url = $this->adminBase().'/users/'.$id.'/reset-password';
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->put($url, [
            'type' => 'password',
            'value' => $password,
            'temporary' => false,
        ]);
        KeycloakCallLogger::keycloak('admin_reset_password', 'PUT', $url, $response->status());
        $this->ensureOk($response, 'Mot de passe Keycloak impossible à définir.');
    }

    private function replaceStaffRealmRole(string $userId, string $targetRole): void
    {
        /** @var list<string> $staff */
        $staff = config('roles.staff', []);
        if (! in_array($targetRole, $staff, true)) {
            throw new StaffKeycloakAdminException('Rôle staff Keycloak invalide.', 502);
        }

        $mapped = $this->realmRoleMappings($userId);
        $staffMapped = [];
        foreach ($mapped as $mapping) {
            $name = $mapping['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $staff, true)) {
                continue;
            }
            $staffMapped[$name] = $mapping;
        }

        $toRemove = [];
        foreach ($staffMapped as $name => $mapping) {
            if ($name !== $targetRole) {
                $toRemove[] = [
                    'id' => $mapping['id'],
                    'name' => $name,
                ];
            }
        }

        if ($toRemove !== []) {
            $url = $this->adminBase().'/users/'.$userId.'/role-mappings/realm';
            $response = Http::withToken($this->token())->acceptJson()->timeout(10)
                ->send('DELETE', $url, ['json' => $toRemove]);
            KeycloakCallLogger::keycloak('admin_delete_realm_roles', 'DELETE', $url, $response->status());
            $this->ensureOk($response, 'Retrait des rôles Keycloak impossible.');
        }

        if (! array_key_exists($targetRole, $staffMapped)) {
            $role = $this->realmRole($targetRole);
            $url = $this->adminBase().'/users/'.$userId.'/role-mappings/realm';
            $payload = [['id' => $role['id'], 'name' => $role['name']]];
            $response = Http::withToken($this->token())->acceptJson()->timeout(10)->post($url, $payload);
            KeycloakCallLogger::keycloak('admin_add_realm_role', 'POST', $url, $response->status());
            $this->ensureOk($response, 'Attribution du rôle Keycloak impossible.');
        }
    }

    /**
     * @return list<array{id?: mixed, name?: mixed}>
     */
    private function realmRoleMappings(string $userId): array
    {
        $url = $this->adminBase().'/users/'.$userId.'/role-mappings/realm';
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->get($url);
        KeycloakCallLogger::keycloak('admin_list_realm_roles', 'GET', $url, $response->status());
        $this->ensureOk($response, 'Lecture des rôles Keycloak impossible.');

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function realmRole(string $name): array
    {
        $url = $this->adminBase().'/roles/'.rawurlencode($name);
        $response = Http::withToken($this->token())->acceptJson()->timeout(10)->get($url);
        KeycloakCallLogger::keycloak('admin_get_realm_role', 'GET', $url, $response->status());
        $this->ensureOk($response, 'Rôle realm Keycloak introuvable ('.$name.').');

        $json = $response->json();
        $id = is_array($json) ? ($json['id'] ?? null) : null;
        $roleName = is_array($json) ? ($json['name'] ?? null) : null;
        if (! is_string($id) || $id === '' || ! is_string($roleName) || $roleName === '') {
            throw new StaffKeycloakAdminException('Rôle realm Keycloak illisible.', 502);
        }

        return ['id' => $id, 'name' => $roleName];
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = $this->tokenUri();
        $clientId = config('keycloak.staff.admin_client_id');
        $response = Http::asForm()->timeout(10)->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => config('keycloak.staff.admin_client_secret'),
        ]);

        $accessToken = $response->json('access_token');
        KeycloakCallLogger::keycloak('admin_token', 'POST', $url, $response->status(), [
            'grant_type' => 'client_credentials',
            'client_id' => is_string($clientId) ? $clientId : null,
            'token_returned' => is_string($accessToken) && $accessToken !== '',
        ]);

        $this->ensureOk($response, 'Jeton admin Keycloak impossible à obtenir.');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new StaffKeycloakAdminException('Jeton admin Keycloak manquant.', 502);
        }

        $expiresIn = (int) $response->json('expires_in', 60);
        Cache::put(self::TOKEN_CACHE_KEY, $accessToken, now()->addSeconds(max(30, $expiresIn - 30)));

        return $accessToken;
    }

    private function tokenUri(): string
    {
        $uri = config('keycloak.staff.token_uri');
        if (is_string($uri) && $uri !== '') {
            return $uri;
        }

        return $this->issuer().'/protocol/openid-connect/token';
    }

    private function issuer(): string
    {
        $issuer = config('keycloak.staff.issuer');
        if (! is_string($issuer) || $issuer === '') {
            throw new StaffKeycloakAdminException('Issuer Keycloak staff non configuré.', 503);
        }

        return rtrim($issuer, '/');
    }

    private function adminBase(): string
    {
        if (! preg_match('#^(https?://[^/]+)/realms/([^/]+)$#', $this->issuer(), $matches)) {
            throw new StaffKeycloakAdminException('Issuer Keycloak staff invalide.', 503);
        }

        return $matches[1].'/admin/realms/'.$matches[2];
    }

    private function idFromLocation(mixed $location): ?string
    {
        if (is_array($location)) {
            $location = $location[0] ?? null;
        }
        if (! is_string($location) || $location === '') {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH);
        $id = basename(is_string($path) && $path !== '' ? $path : $location);

        return $id !== '' ? $id : null;
    }

    private function ensureOk(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new StaffKeycloakAdminException($message, 502);
    }

    private function ensureCreated(Response $response, string $message): void
    {
        if (in_array($response->status(), [200, 201, 204], true)) {
            return;
        }

        throw new StaffKeycloakAdminException($message, 502);
    }
}
