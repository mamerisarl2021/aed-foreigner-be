<?php

namespace App\Services\PKI;

use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrustedXClientService
{
    private $TX_CLIENT_ID;

    private $TX_BASE_URL;

    private $TIMESATAMP_API_BASE_URL;

    private $TIMESATAMP_API_USERNAME;

    private $TIMESATAMP_API_PASSWORD;

    private $TX_CLIENTS_LOGGED_AS;

    private $TX_ADMINS_LOGGED_AS;

    private $TX_CLIENT_SECRET;

    private $TX_REDIRECT_URL;

    public function __construct()
    {
        $this->TX_CLIENT_SECRET = config('trustedx.client_secret');
        $this->TX_BASE_URL = config('trustedx.base_url');
        $this->TIMESATAMP_API_BASE_URL = config('trustedx.timestamp.url');
        $this->TIMESATAMP_API_USERNAME = config('trustedx.timestamp.username');
        $this->TIMESATAMP_API_PASSWORD = config('trustedx.timestamp.password');
        $this->TX_CLIENTS_LOGGED_AS = config('trustedx.clients_logged_as');
        $this->TX_ADMINS_LOGGED_AS = config('trustedx.admins_logged_as');
        $this->TX_CLIENT_ID = config('trustedx.client_id');
        $this->TX_REDIRECT_URL = config('trustedx.redirect_url');
    }

    public function obtainToken(string $code)
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth((string) $this->TX_CLIENT_ID, (string) $this->TX_CLIENT_SECRET)
                ->post("https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token?grant_type=authorization_code&code={$code}&redirect_uri={$this->TX_REDIRECT_URL}");

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    public function obtainMobileToken(string $code)
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth((string) $this->TX_CLIENT_ID, (string) $this->TX_CLIENT_SECRET)
                ->post("https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token?grant_type=authorization_code&code={$code}&redirect_uri=com.aed.mobile://auth");

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    public function userInfo(string $code)
    {
        try {
            $resp = $this->obtainToken($code);
            if ($resp['status']) {
                $access_token = $resp['data']['access_token'];
            } else {
                return [
                    'status' => false,
                    'message' => $resp['message'],
                ];
            }
            // Use the obtained access token for user info request
            $response = Http::withOptions([
                'verify' => false,
            ])->withToken($access_token)->get("https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me");

            $existingUser = User::with(['identities' => function ($query) {
                $query->select('user_id', 'type', 'level', 'status');
            }])->where('npi', $response['npi'])->first();

            if (! $existingUser) {
                return [
                    'status' => false,
                    'message' => 'Aucun utilisateur ne correspond au NPI renseigné.',
                ];
            }
            if ($existingUser->status != 'ACTIVE') {
                return [
                    'status' => false,
                    'message' => "Votre compte a été désactivé par un agent veuillez contacter le service clientèle pour plus d'informations.",
                ];
            }

            $token = $existingUser->createToken($existingUser->email.'-'.now())->plainTextToken;
            $existingUser['first_name'] = $response['first_name'];
            $existingUser['last_name'] = $response['last_name'];
            $existingUser['name'] = $response['name'];
            $existingUser['pki_id'] = $response['sub'];

            if ($token) {
                $data = [
                    'user' => $existingUser,
                    'token' => $token,
                    'pki_token' => $access_token,
                ];

                return [
                    'status' => true,
                    'data' => $data,
                    'message' => "Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!",
                ];
            }

            return ['status' => false, 'message' => 'Une erreur est survenue.'];
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'];
        }
    }

    public function mobileUserInfo(string $code)
    {
        try {
            $resp = $this->obtainMobileToken($code);
            if ($resp['status']) {
                $access_token = $resp['data']['access_token'];
            } else {
                return [
                    'status' => false,
                    'message' => $resp['message'],
                ];
            }
            // Use the obtained access token for user info request
            $response = Http::withOptions([
                'verify' => false,
            ])->withToken($access_token)->get("https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me");

            $existingUser = User::with(['identities' => function ($query) {
                $query->select('user_id', 'type', 'level', 'status');
            }])->where('npi', $response['npi'])->first();

            if (! $existingUser) {
                return [
                    'status' => false,
                    'message' => 'Aucun utilisateur ne correspond au NPI renseigné.',
                ];
            }
            if ($existingUser->status != 'ACTIVE') {
                return [
                    'status' => false,
                    'message' => "Votre compte a été désactivé par un agent veuillez contacter le service clientèle pour plus d'informations.",
                ];
            }

            $token = $existingUser->createToken($existingUser->email.'-'.now())->plainTextToken;
            $existingUser['first_name'] = $response['first_name'];
            $existingUser['last_name'] = $response['last_name'];
            $existingUser['name'] = $response['name'];
            $existingUser['pki_id'] = $response['sub'];

            if ($token) {
                $data = [
                    'user' => $existingUser,
                    'token' => $token,
                    'pki_token' => $access_token,
                ];

                return [
                    'status' => true,
                    'data' => $data,
                    'message' => "Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!",
                ];
            }

            return ['status' => false, 'message' => 'Une erreur est survenue.'];
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'];
        }
    }

    public function register(array $user)
    {
        try {
            $foundUser = $this->getUserWithNPI($user['data']['npi']);
            if ($foundUser['status']) {
                return ['status' => true, 'message' => 'Nous avons retrouvé un utilisateur existant avec le même NPI voullez vous poursuivre en tant que ce dernier ?', 'data' => $foundUser['data'], 'has_user' => true];
            }

            $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:list urn:safelayer:eidas:account:user:attributes:manage urn:safelayer:eidas:account:user:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => $tokenResponse['message']];
            }
            $client = new Client;
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $payload = [
                'npi' => $user['data']['npi'],
                'uuid' => $user['data']['npi'],
                'preferred2fa' => 'sms',
                'status' => 'enabled',
                'role' => 'ROLE_SUBSCRIBER',
                'first_login' => 'YES',
            ];
            $request = new Psr7Request('POST', 'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users', $headers, json_encode($payload));
            $response = $client->sendAsync($request)->wait();
            if ($response->getStatusCode() == 200) {
                $output = ['status' => true, 'data' => json_decode($response->getBody()->getContents(), true)];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû vous créer votre copte nous vous prions de réessayer.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to create account: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Il se pourrait qu\'il y ait un problème avec un ou plusieurs des champs renseignés'];
        }
    }

    public function setDefaultPassword(array $user, string $type)
    {
        try {
            $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:passwords:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pu récupérer le token d\'authentification.'];
            }
            $client = new Client;
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $body = [
                'value' => $user['password'],
                'max_attempts' => 3,
            ];
            $request = new Psr7Request('PUT', 'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users/'.$user['id']."/passwords/$type", $headers, json_encode($body));
            $response = $client->sendAsync($request)->wait();
            if ($response->getStatusCode() == 200) {
                $output = ['status' => true, 'data' => json_decode($response->getBody()->getContents(), true)];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû vous créer un mot de passe par défaut nous vous prions de réessayer.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to set password: '.$e->getMessage());

            return ['status' => false, 'message' => 'Erreur au cours de l\'initialisation de votre mot de passe.'];
        }
    }

    public function getToken(string $scope)
    {
        try {
            $credentials = base64_encode("{$this->TX_CLIENT_ID}:{$this->TX_CLIENT_SECRET}");

            $client = new Client;
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.$credentials,
            ];
            $options = [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => $scope,
                ],
            ];

            $request = new Psr7Request('POST', "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_ADMINS_LOGGED_AS}/token", $headers);
            $response = $client->sendAsync($request, $options)->wait();

            if ($response->getStatusCode() == 200) {
                return ['status' => true, 'token' => json_decode($response->getBody()->getContents(), true)['access_token']];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pû vous envoyer le message veuillez réessayer.'];
            }
        } catch (Exception $e) {
            Log::error('Failed to get Token: '.$e->getMessage());

            return ['status' => false, 'message' => 'Problème de récupération du token.'];
        }
    }

    public function getClientToken(string $scope)
    {
        try {
            $credentials = base64_encode("{$this->TX_CLIENT_ID}:{$this->TX_CLIENT_SECRET}");

            $client = new Client;
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.$credentials,
            ];
            $options = [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => $scope,
                ],
            ];

            $request = new Psr7Request('POST', "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token", $headers);
            $response = $client->sendAsync($request, $options)->wait();

            if ($response->getStatusCode() == 200) {
                return ['status' => true, 'token' => json_decode($response->getBody()->getContents(), true)['access_token']];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pû vous envoyer le message veuillez réessayer.'];
            }
        } catch (Exception $e) {
            Log::error('Failed to get Token: '.$e->getMessage());

            return ['status' => false, 'message' => 'Problème de récupération du token.'];
        }
    }

    public function getUserWithNPI(string $npi)
    {
        try {
            $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:list urn:safelayer:eidas:account:user:attributes:manage urn:safelayer:eidas:account:user:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => $tokenResponse['message']];
            }
            $client = new Client;
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $request = new Psr7Request('GET', 'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users?filter=npi eq "'.$npi.'"', $headers);
            $response = $client->sendAsync($request)->wait();
            $jsonResponse = $response->getBody()->getContents();
            if ($response->getStatusCode() == 200 && ! empty(json_decode($jsonResponse, true)['users'])) {
                $output = ['status' => true, 'data' => json_decode($jsonResponse, true)['users'][0]];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû récupérer l\'utilisateur à partir du npi spécifié.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to get account: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Erreur de récupération de l\'utilisateur à partir de son NPI'];
        }
    }

    public function updateUserAttributesByNPI(string $npi, string $newAttributes, int $years)
    {
        try {
            // Retrieve the user using the NPI
            $userResponse = $this->getUserWithNPI($npi);

            if ($userResponse['status']) {
                $userId = $userResponse['data']['id'];
                $userIFUs = isset($userResponse['data']['ifus']) ? $userResponse['data']['ifus'] : [];

                // Prepare the data to be updated
                $updateData = [
                    'ifus' => [$newAttributes, ...$userIFUs],
                    'mobile_cert_time' => "P{$years}Y0M0DT0H0M",
                    'server_cert_time' => "P{$years}Y0M0DT0H0M",
                ];

                // Get the token for authorization
                $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:attributes:manage');
                if ($tokenResponse['status']) {
                    $token = $tokenResponse['token'];
                } else {
                    return ['status' => false, 'message' => $tokenResponse['message']];
                }

                // Make the API call to update the user's attributes
                $client = new Client;
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ];

                $request = new Psr7Request(
                    'PATCH',
                    'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users/'.$userId,
                    $headers,
                    json_encode($updateData, true)
                );
                $response = $client->sendAsync($request)->wait();
                if ($response->getStatusCode() == 204) {
                    return ['status' => true, 'message' => 'User attributes updated successfully.'];
                } else {
                    Log::error('Failed to update user attributes: '.json_encode($response));

                    return ['status' => false, 'message' => 'Failed to update user attributes.'];
                }
            } else {
                Log::error('Failed to update user attributes: '.$userResponse['message']);

                return ['status' => false, 'message' => $userResponse['message']];
            }
        } catch (Exception $e) {
            Log::error('Failed to update user attributes: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'An error occurred while updating user attributes.'];
        }
    }

    public function updateCertValidityByNPI(string $npi, int $years)
    {
        try {
            // Retrieve the user using the NPI
            $userResponse = $this->getUserWithNPI($npi);

            if ($userResponse['status']) {
                $userId = $userResponse['data']['id'];

                // Prepare the data to be updated
                $updateData = [
                    'mobile_cert_time' => "P{$years}Y0M0DT0H0M",
                    'server_cert_time' => "P{$years}Y0M0DT0H0M",
                ];

                // Get the token for authorization
                $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:attributes:manage');
                if ($tokenResponse['status']) {
                    $token = $tokenResponse['token'];
                } else {
                    return ['status' => false, 'message' => $tokenResponse['message']];
                }

                // Make the API call to update the user's attributes
                $client = new Client;
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ];

                $request = new Psr7Request(
                    'PATCH',
                    'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users/'.$userId,
                    $headers,
                    json_encode($updateData, true)
                );
                $response = $client->sendAsync($request)->wait();
                if ($response->getStatusCode() == 204) {
                    return ['status' => true, 'message' => 'User attributes updated successfully.'];
                } else {
                    Log::error('Failed to update user attributes: '.json_encode($response));

                    return ['status' => false, 'message' => 'Failed to update user attributes.'];
                }
            } else {
                Log::error('Failed to update user attributes: '.$userResponse['message']);

                return ['status' => false, 'message' => $userResponse['message']];
            }
        } catch (Exception $e) {
            Log::error('Failed to update user attributes: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'An error occurred while updating user attributes.'];
        }
    }
}
