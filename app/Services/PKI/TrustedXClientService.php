<?php

declare(strict_types=1);

namespace App\Services\PKI;

use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class TrustedXClientService
{
    private string $TX_CLIENT_ID;

    private string $TX_BASE_URL;

    private string $TIMESATAMP_API_BASE_URL;

    private string $TIMESATAMP_API_USERNAME;

    private string $TIMESATAMP_API_PASSWORD;

    private string $TX_CLIENTS_LOGGED_AS;

    private string $TX_ADMINS_LOGGED_AS;

    private string $TX_CLIENT_SECRET;

    private string $TX_REDIRECT_URL;

    public function __construct()
    {
        $this->TX_CLIENT_SECRET = (string) config('trustedx.client_secret');
        $this->TX_BASE_URL = (string) config('trustedx.base_url');
        $this->TIMESATAMP_API_BASE_URL = (string) config('trustedx.timestamp.url');
        $this->TIMESATAMP_API_USERNAME = (string) config('trustedx.timestamp.username');
        $this->TIMESATAMP_API_PASSWORD = (string) config('trustedx.timestamp.password');
        $this->TX_CLIENTS_LOGGED_AS = (string) config('trustedx.clients_logged_as');
        $this->TX_ADMINS_LOGGED_AS = (string) config('trustedx.admins_logged_as');
        $this->TX_CLIENT_ID = (string) config('trustedx.client_id');
        $this->TX_REDIRECT_URL = (string) config('trustedx.redirect_url');
    }

    /**
     * @return array<string, mixed>
     */
    public function obtainToken(string $code): array
    {
        $url = "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token?grant_type=authorization_code&code={$code}&redirect_uri={$this->TX_REDIRECT_URL}";
        try {
            $response = Http::withOptions([
                'verify' => $this->sslVerify(),
            ])->withBasicAuth((string) $this->TX_CLIENT_ID, (string) $this->TX_CLIENT_SECRET)
                ->post($url);

            $this->logCall('obtainToken', 'POST', $url, $response->status(), [
                'access_token' => $response->json('access_token'),
            ]);

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            $this->logCall('obtainToken', 'POST', $url, 0, [
                'error' => $e->getMessage(),
            ]);
            Log::error($e->getMessage(), $e->getTrace());

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function obtainMobileToken(string $code): array
    {
        $url = "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token?grant_type=authorization_code&code={$code}&redirect_uri=com.aed.mobile://auth";
        try {
            $response = Http::withOptions([
                'verify' => $this->sslVerify(),
            ])->withBasicAuth((string) $this->TX_CLIENT_ID, (string) $this->TX_CLIENT_SECRET)
                ->post($url);

            $this->logCall('obtainMobileToken', 'POST', $url, $response->status(), [
                'access_token' => $response->json('access_token'),
            ]);

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            $this->logCall('obtainMobileToken', 'POST', $url, 0, [
                'error' => $e->getMessage(),
            ]);
            Log::error($e->getMessage(), $e->getTrace());

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function userInfo(string $code): array
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
            $userInfoUrl = "https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me";
            $response = Http::withOptions([
                'verify' => $this->sslVerify(),
            ])->withToken($access_token)->get($userInfoUrl);

            $this->logCall('userInfo', 'GET', $userInfoUrl, $response->status(), [
                'npi' => $response['npi'] ?? null,
                'access_token' => $access_token,
            ]);

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
            $existingUser->withTrustedxProfile([
                'first_name' => $response['first_name'],
                'last_name' => $response['last_name'],
                'name' => $response['name'],
                'pki_id' => $response['sub'],
            ]);

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
            $this->logCall('userInfo', 'GET', "https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me", 0, [
                'error' => $e->getMessage(),
            ]);
            Log::error($e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function mobileUserInfo(string $code): array
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
            $userInfoUrl = "https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me";
            $response = Http::withOptions([
                'verify' => $this->sslVerify(),
            ])->withToken($access_token)->get($userInfoUrl);

            $this->logCall('mobileUserInfo', 'GET', $userInfoUrl, $response->status(), [
                'npi' => $response['npi'] ?? null,
                'access_token' => $access_token,
            ]);

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
            $existingUser->withTrustedxProfile([
                'first_name' => $response['first_name'],
                'last_name' => $response['last_name'],
                'name' => $response['name'],
                'pki_id' => $response['sub'],
            ]);

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
            $this->logCall('mobileUserInfo', 'GET', "https://{$this->TX_BASE_URL}/trustedx-resources/openid/v1/users/me", 0, [
                'error' => $e->getMessage(),
            ]);
            Log::error($e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'];
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function register(array $user): array
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
            $url = "https://{$this->TX_BASE_URL}/trustedx-resources/accounts/v1/users";
            $request = new Psr7Request('POST', $url, $headers, json_encode($payload));
            $response = $this->sendRequest('register', $request, [
                'npi' => $user['data']['npi'] ?? null,
                'access_token' => $token,
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode == 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $output = ['status' => true, 'data' => $data];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû vous créer votre copte nous vous prions de réessayer.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to create account: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Il se pourrait qu\'il y ait un problème avec un ou plusieurs des champs renseignés'];
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function setDefaultPassword(array $user, string $type): array
    {
        try {
            $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:passwords:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pu récupérer le token d\'authentification.'];
            }
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $body = [
                'value' => $user['password'],
                'max_attempts' => 3,
            ];
            $url = "https://{$this->TX_BASE_URL}/trustedx-resources/accounts/v1/users/".$user['id']."/passwords/$type";
            $request = new Psr7Request('PUT', $url, $headers, json_encode($body));
            $secretKey = $type === 'pin' ? 'pin' : 'password';
            $response = $this->sendRequest('setDefaultPassword', $request, [
                'type' => $type,
                'trustedx_user_id' => $user['id'] ?? null,
                $secretKey => $user['password'] ?? null,
                'access_token' => $token,
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode == 200) {
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

    /**
     * @return array<string, mixed>
     */
    public function getToken(string $scope): array
    {
        try {
            $credentials = base64_encode("{$this->TX_CLIENT_ID}:{$this->TX_CLIENT_SECRET}");

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

            $url = "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_ADMINS_LOGGED_AS}/token";
            $request = new Psr7Request('POST', $url, $headers);
            $response = $this->sendRequest('getToken', $request, [
                'scope' => $scope,
            ], $options, false);
            $statusCode = $response->getStatusCode();

            if ($statusCode == 200) {
                $accessToken = json_decode($response->getBody()->getContents(), true)['access_token'] ?? null;
                $this->logCall('getToken', 'POST', $url, $statusCode, [
                    'scope' => $scope,
                    'access_token' => $accessToken,
                ]);

                return ['status' => true, 'token' => $accessToken];
            } else {
                $this->logCall('getToken', 'POST', $url, $statusCode, [
                    'scope' => $scope,
                ]);

                return ['status' => false, 'message' => 'Nous n\'avons pas pû vous envoyer le message veuillez réessayer.'];
            }
        } catch (Exception $e) {
            Log::error('Failed to get Token: '.$e->getMessage());

            return ['status' => false, 'message' => 'Problème de récupération du token.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getClientToken(string $scope): array
    {
        try {
            $credentials = base64_encode("{$this->TX_CLIENT_ID}:{$this->TX_CLIENT_SECRET}");

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

            $url = "https://{$this->TX_BASE_URL}/trustedx-authserver/oauth/{$this->TX_CLIENTS_LOGGED_AS}/token";
            $request = new Psr7Request('POST', $url, $headers);
            $response = $this->sendRequest('getClientToken', $request, [
                'scope' => $scope,
            ], $options, false);

            $statusCode = $response->getStatusCode();
            if ($statusCode == 200) {
                $accessToken = json_decode($response->getBody()->getContents(), true)['access_token'] ?? null;
                $this->logCall('getClientToken', 'POST', $url, $statusCode, [
                    'scope' => $scope,
                    'access_token' => $accessToken,
                ]);

                return ['status' => true, 'token' => $accessToken];
            }

            $this->logCall('getClientToken', 'POST', $url, $statusCode, [
                'scope' => $scope,
            ]);

            return ['status' => false, 'message' => 'Nous n\'avons pas pû vous envoyer le message veuillez réessayer.'];
        } catch (Exception $e) {
            Log::error('Failed to get Token: '.$e->getMessage());

            return ['status' => false, 'message' => 'Problème de récupération du token.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserWithNPI(string $npi): array
    {
        try {
            $tokenResponse = $this->getToken('urn:safelayer:eidas:account:user:list urn:safelayer:eidas:account:user:attributes:manage urn:safelayer:eidas:account:user:manage');
            if ($tokenResponse['status']) {
                $token = $tokenResponse['token'];
            } else {
                return ['status' => false, 'message' => $tokenResponse['message']];
            }
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ];
            $url = "https://{$this->TX_BASE_URL}/trustedx-resources/accounts/v1/users?filter=npi eq \"".$npi.'"';
            $request = new Psr7Request('GET', $url, $headers);
            $response = $this->sendRequest('getUserWithNPI', $request, [
                'npi' => $npi,
                'access_token' => $token,
            ]);
            $jsonResponse = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            $decoded = json_decode($jsonResponse, true);
            if ($statusCode == 200 && ! empty($decoded['users'])) {
                $userData = $decoded['users'][0];
                $output = ['status' => true, 'data' => $userData];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû récupérer l\'utilisateur à partir du npi spécifié.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to get account: '.$e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Erreur de récupération de l\'utilisateur à partir de son NPI'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function updateUserAttributesByNPI(string $npi, string $newAttributes, int $years): array
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
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ];

                $url = "https://{$this->TX_BASE_URL}/trustedx-resources/accounts/v1/users/".$userId;
                $request = new Psr7Request(
                    'PATCH',
                    $url,
                    $headers,
                    json_encode($updateData, true)
                );
                $response = $this->sendRequest('updateUserAttributesByNPI', $request, [
                    'npi' => $npi,
                    'trustedx_user_id' => $userId,
                    'access_token' => $token,
                ]);
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

    /**
     * @return array<string, mixed>
     */
    public function updateCertValidityByNPI(string $npi, int $years): array
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
                $headers = [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ];

                $url = "https://{$this->TX_BASE_URL}/trustedx-resources/accounts/v1/users/".$userId;
                $request = new Psr7Request(
                    'PATCH',
                    $url,
                    $headers,
                    json_encode($updateData, true)
                );
                $response = $this->sendRequest('updateCertValidityByNPI', $request, [
                    'npi' => $npi,
                    'trustedx_user_id' => $userId,
                    'access_token' => $token,
                ]);
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

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    private function sendRequest(string $operation, Psr7Request $request, array $context = [], array $options = [], bool $shouldLog = true): ResponseInterface
    {
        try {
            $response = (new Client(['verify' => $this->sslVerify()]))->sendAsync($request, $options)->wait();
            if (! $response instanceof ResponseInterface) {
                throw new Exception('TrustedX a renvoyé une réponse invalide.');
            }

            if ($shouldLog) {
                $this->logCall($operation, $request->getMethod(), (string) $request->getUri(), $response->getStatusCode(), $context);
            }

            return $response;
        } catch (Exception $e) {
            $this->logCall($operation, $request->getMethod(), (string) $request->getUri(), 0, array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    private function sslVerify(): bool
    {
        return (bool) config('trustedx.verify_ssl', true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logCall(string $operation, string $method, string $url, int $statusCode, array $context = []): void
    {
        if (! config('trustedx.log_calls')) {
            return;
        }

        Log::info('TrustedX call', array_merge([
            'operation' => $operation,
            'method' => $method,
            'url' => $url,
            'status' => $statusCode,
        ], $context));
    }
}
