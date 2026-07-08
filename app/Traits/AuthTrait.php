<?php

namespace App\Traits;

use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait AuthTrait
{
    /**
     * @var Model
     */
    protected $model;

    protected $client;

    private $TX_CLIENT_ID;

    private $TX_BASE_URL;

    private $TIMESATAMP_API_BASE_URL;

    private $TIMESATAMP_API_USERNAME;

    private $TIMESATAMP_API_PASSWORD;

    private $TX_CLIENTS_LOGGED_AS;

    private $TX_ADMINS_LOGGED_AS;

    private $ANIP_BASE_URL;

    private $TX_CLIENT_SECRET;

    private $TX_REDIRECT_URL;

    public function __construct(User $model)
    {
        $this->model = $model;
        $this->TX_CLIENT_SECRET = config('trustedx.client_secret');
        $this->TX_BASE_URL = config('trustedx.base_url');
        $this->TIMESATAMP_API_BASE_URL = config('trustedx.timestamp.url');
        $this->TIMESATAMP_API_USERNAME = config('trustedx.timestamp.username');
        $this->TIMESATAMP_API_PASSWORD = config('trustedx.timestamp.password');
        $this->TX_CLIENTS_LOGGED_AS = config('trustedx.clients_logged_as');
        $this->TX_ADMINS_LOGGED_AS = config('trustedx.admins_logged_as');
        $this->TX_ANIP_BASE_URL = config('trustedx.anip_base_url');
        $this->TX_CLIENT_ID = config('trustedx.client_id');
        $this->TX_REDIRECT_URL = config('trustedx.redirect_url');
    }

    public function obtainToken(string $code)
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withBasicAuth($this->TX_CLIENT_ID, $this->TX_CLIENT_SECRET)->post("https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_CLIENTS_LOGGED_AS/token?grant_type=authorization_code&code=$code&redirect_uri=$this->TX_REDIRECT_URL");

            // if(response)
            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), $e);

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
            ])->withBasicAuth($this->TX_CLIENT_ID, $this->TX_CLIENT_SECRET)->post("https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_CLIENTS_LOGGED_AS/token?grant_type=authorization_code&code=$code&redirect_uri=com.aed.mobile://auth");

            return [
                'status' => true,
                'data' => $response->json(),
                'message' => 'Poursuivez avec ce token.',
            ];
        } catch (HttpClientException $e) {
            Log::error($e->getMessage(), $e);

            return [
                'status' => false,
                'message' => 'Il semblerait que le code fournis soit invalide ou expiré.',
            ];
        }
    }

    public function getUserData($npi)
    {
        try {

            $response = Http::withBasicAuth('admin', 'supersecret')->get("https://local-simulator.qcdigitalhub.com/api/user/{$npi}");

            if ($response->successful()) {
                return [
                    'status' => true,
                    'data' => [
                        ...$response->json(),
                        'role' => 'CLIENT',
                    ],
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Ce NPI ne correspond à aucun utilisateur.',
                ];
            }
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function userInfo($code)
    {
        try {
            $resp = $this->obtainToken($code);
            if ($resp['status']) {
                Log::debug($resp);
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
            ])->withToken($access_token)->get("https://$this->TX_BASE_URL/trustedx-resources/openid/v1/users/me");
            $existingUser = User::with(['identities' => function ($query) {
                $query->select('user_id', 'type', 'level', 'status');
            }])->where('npi', $response['npi'])->first();

            if (! $existingUser) {
                $exoutput = [
                    'status' => false,
                    'message' => 'Aucun utilisateur ne correspond au NPI renseigné.',
                ];

                return $exoutput;
            }
            if ($existingUser && $existingUser->status != 'ACTIVE') {
                $exoutput = [
                    'status' => false,
                    'message' => "Votre compte a été désactivé par un agent veuillez contacter le service clientèle pour plus d'informations.",
                ];

                return $exoutput;
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
                $output = [
                    'status' => true,
                    'data' => $data,
                    'message' => "Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!",
                ];
            }

            return $output;
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e);

            return response()->json(['error' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'], 400);
        }
    }

    public function mobileUserInfo($code)
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
            ])->withToken($access_token)->get("https://$this->TX_BASE_URL/trustedx-resources/openid/v1/users/me");
            $existingUser = User::with([
                'cases',
                'structures',
                'userSubscriptions',
                'identities',
                'signatures',
            ])->where('npi', $response['npi'])->first();

            if (! $existingUser) {
                $exoutput = [
                    'status' => false,
                    'message' => 'Aucun utilisateur ne correspond au NPI renseigné.',
                ];

                return $exoutput;
            }
            if ($existingUser && $existingUser->status != 'ACTIVE') {
                $exoutput = [
                    'status' => false,
                    'message' => "Votre compte a été désactivé par un agent veuillez contacter le service clientèle pour plus d'informations.",
                ];

                return $exoutput;
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
                $output = [
                    'status' => true,
                    'data' => $data,
                    'message' => "Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!",
                ];
            }

            return $output;
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e);

            return response()->json(['error' => 'Une erreur est survenue au cours du processus! Nous vous prions de reéssayer ultérieurement.'], 400);
        }
    }

    public function sendSms(string $phoneNumber, string $otp)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => 'SERVERID=A',
            ])->asForm()->post('https://api-public-2.mtarget.fr/messages', [
                'username' => 'username',
                'password' => 'password',
                'msisdn' => $phoneNumber,
                'msg' => 'Votre code OTP est le suivant: '.$otp,
            ]);

            if ($response->successful()) {
                return ['status' => true];
            } else {
                return ['status' => false, 'message' => 'Nous n\'avons pas pû vous envoyer le message veuillez réessayer.'];
            }
        } catch (Exception $e) {
            Log::error('Failed to send SMS: '.$e->getMessage());

            return ['status' => false, 'message' => 'Erreur au cours de l\'envoi du SMS.'];
        }
    }

    public function register(mixed $user)
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
            $user = [
                'npi' => $user['data']['npi'],
                'uuid' => $user['data']['npi'],
                'preferred2fa' => 'sms',
                'status' => 'enabled',
                'role' => 'ROLE_SUBSCRIBER',
                'first_login' => 'YES',
            ];
            $request = new Psr7Request('POST', 'https://test-tx-pki.gouv.bj/trustedx-resources/accounts/v1/users', $headers, json_encode($user));
            $response = $client->sendAsync($request)->wait();
            if ($response->getStatusCode() == 200) {
                $output = ['status' => true, 'data' => json_decode($response->getBody()->getContents(), true)];
            } else {
                $output = ['status' => false, 'message' => 'Nous n\'avons pas pû vous créer votre copte nous vous prions de réessayer.'];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to create account: '.$e->getMessage(), $e->getTrace());

            // The substring was not found
            return ['status' => false, 'message' => 'Il se pourrait qu\'il y ait un problème avec un ou plusieurs des champs renseignés'];
        }
    }

    public function setDefaultPassword(mixed $user, string $type)
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
            $credentials = base64_encode("$this->TX_CLIENT_ID:$this->TX_CLIENT_SECRET");

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

            $request = new Psr7Request('POST', "https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_ADMINS_LOGGED_AS/token", $headers);
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
            $credentials = base64_encode("$this->TX_CLIENT_ID:$this->TX_CLIENT_SECRET");

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

            $request = new Psr7Request('POST', "https://$this->TX_BASE_URL/trustedx-authserver/oauth/$this->TX_CLIENTS_LOGGED_AS/token", $headers);
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

    public function addUserToAD(mixed $request)
    {
        try {
            $ldapUser = [
                'cn' => $request->input('name'),
                'sn' => explode($request->input('name'), ' ')[0],
                'mail' => $request->input('email'),
                'uid' => $request->input('npi').$request->input('phonenumber'),
                'userPassword' => 'default',
                'objectClass' => ['inetOrgPerson', 'organizationalPerson', 'person', 'top'],
            ];
            $ldapAdded = $this->createLdapEntry('cn='.$request->input('name').',ou=Admins,ou=AED,dc=gouv-test,dc=bj', $ldapUser);

            if ($ldapAdded) {
                $output = [
                    'status' => true,
                    'data' => null,
                ];
            } else {
                $output = [
                    'status' => false,
                    'message' => 'Nous n\'avons pas pû synchroniser ces informations avec l\'annuaire veuillez réessayer.',
                ];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to add user: '.$e->getMessage());

            return ['status' => false, 'message' => 'Erreur au cours de l\'ajout dans l\'AD.'];
        }
    }

    public function addClientsToAD(mixed $request)
    {
        try {
            $ldapUser = [
                'cn' => $request['name'],
                'sn' => $request['first_name'],
                'mail' => $request['email'],
                'uid' => $request['npi'],
                'userPassword' => 'default',
                'objectClass' => ['inetOrgPerson', 'organizationalPerson', 'person', 'top'],
            ];

            $ldapAdded = $this->createLdapEntry('cn='.$request['name'].',ou=Users,ou=AED,dc=gouv-test,dc=bj', $ldapUser);

            if ($ldapAdded) {
                $output = [
                    'status' => true,
                    'data' => null,
                ];
            } else {
                $output = [
                    'status' => false,
                    'message' => 'Nous n\'avons pas pû synchroniser ces informations avec l\'annuaire veuillez réessayer.',
                ];
            }

            return $output;
        } catch (Exception $e) {
            Log::error('Failed to add user: '.$e->getMessage());

            return ['status' => false, 'message' => 'Erreur au cours de l\'ajout dans l\'AD.'];
        }
    }

    private function handleResponse($response)
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            return [
                'status' => true,
                'code' => $statusCode,
                'data' => json_decode($response->getBody(), true),
            ];
        } elseif ($statusCode === 401) {
            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Unauthorized',
            ];
        } elseif ($statusCode === 404) {
            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Aucun process avec l\'id fourni n\'a été trouvé.',
            ];
        } else {
            Log::error('Une erreur est survenue lors de la récupération des informations:', ['error' => $response->getBody()]);

            return [
                'status' => false,
                'code' => $statusCode,
                'data' => 'Nous avons des difficultés à communiquer avec la PKI veuillez réessayer un peu plus tard.',
            ];
        }
    }
}
