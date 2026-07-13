<?php


namespace App\Traits;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;

trait ADTrait
{
    public function createUserInLDAP(array $userData)
    {
        try {
            // Prepare the data to be sent in the request
            $requestData = [
                'dn' => $userData['dn'], // Distinguished Name
                'attributes' => [
                    'cn' => $userData['cn'], // Common Name
                    'sn' => $userData['sn'], // Surname
                    'mail' => $userData['mail'], // Email
                    'uid' => $userData['uid'], // User ID
                    'userPassword' => $userData['userPassword'], // Password
                    'objectClass' => $userData['objectClass'], // Object Classes
                ],
            ];

            // Initialize the Guzzle client
            $client = new Client();

            // Set the headers
            $headers = [
                'Content-Type' => 'application/json'
            ];

            // Create the request
            $request = new Request(
                'POST',
                'http://localhost:5000/users', // URL to send the request to
                $headers,
                json_encode($requestData) // JSON encode the request body
            );

            // Send the request asynchronously
            $response = $client->sendAsync($request)->wait();

            // Check for a successful response
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                return [
                    'status' => true,
                    'message' => 'User created successfully in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Failed to create user in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to create user in LDAP: ' . $e->getMessage(), $e->getTrace());
            return [
                'status' => false,
                'message' => 'An error occurred while creating the user in LDAP.'
            ];
        }
    }

    public function updateUserAttributesInLDAP(array $userData)
    {
        try {
            // Prepare the data to be sent in the request
            $requestData = [
                'dn' => $userData['dn'], // Distinguished Name
                'attributes' => [
                    'cn' => $userData['cn'], // Common Name
                    'mail' => $userData['mail'], // Email
                ],
            ];

            // Initialize the Guzzle client
            $client = new Client();

            // Set the headers
            $headers = [
                'Content-Type' => 'application/json'
            ];

            // Create the PUT request
            $request = new Request(
                'PUT',
                'http://localhost:5000/users', // URL to send the request to
                $headers,
                json_encode($requestData) // JSON encode the request body
            );

            // Send the request asynchronously
            $response = $client->sendAsync($request)->wait();

            // Check for a successful response
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 204) {
                return [
                    'status' => true,
                    'message' => 'User attributes updated successfully in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Failed to update user attributes in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to update user attributes in LDAP: ' . $e->getMessage(), $e->getTrace());
            return [
                'status' => false,
                'message' => 'An error occurred while updating the user attributes in LDAP.'
            ];
        }
    }
    public function deleteOrganizationalUnitInLDAP(string $ouName)
    {
        try {
            // Initialize the Guzzle client
            $client = new Client();

            // Create the DELETE request
            $request = new Request(
                'DELETE',
                'http://localhost:5000/ous/' . urlencode($ouName) // URL to send the request to
            );

            // Send the request asynchronously
            $response = $client->sendAsync($request)->wait();

            // Check for a successful response
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 204) {
                return [
                    'status' => true,
                    'message' => 'Organizational Unit deleted successfully from LDAP.',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Failed to delete Organizational Unit from LDAP.',
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to delete Organizational Unit from LDAP: ' . $e->getMessage(), $e->getTrace());
            return [
                'status' => false,
                'message' => 'An error occurred while deleting the Organizational Unit from LDAP.'
            ];
        }
    }
    public function createOrganizationalUnitInLDAP(array $ouData)
    {
        try {
            // Prepare the data to be sent in the request
            $requestData = [
                'dn' => $ouData['dn'], // Distinguished Name
                'attributes' => [
                    'ou' => $ouData['ou'], // Organizational Unit Name
                    'description' => $ouData['description'], // Description of the OU
                    'objectClass' => $ouData['objectClass'], // Object Classes
                ],
            ];

            // Initialize the Guzzle client
            $client = new Client();

            // Set the headers
            $headers = [
                'Content-Type' => 'application/json'
            ];

            // Create the request
            $request = new Request(
                'POST',
                'http://localhost:5000/ous', // URL to send the request to
                $headers,
                json_encode($requestData) // JSON encode the request body
            );

            // Send the request asynchronously
            $response = $client->sendAsync($request)->wait();

            // Check for a successful response
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                return [
                    'status' => true,
                    'message' => 'Organizational Unit created successfully in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Failed to create Organizational Unit in LDAP.',
                    'data' => json_decode($response->getBody()->getContents(), true)
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to create Organizational Unit in LDAP: ' . $e->getMessage(), $e->getTrace());
            return [
                'status' => false,
                'message' => 'An error occurred while creating the Organizational Unit in LDAP.'
            ];
        }
    }
}
