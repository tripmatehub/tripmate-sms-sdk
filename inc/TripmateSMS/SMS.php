<?php

namespace TripmateSMS;

class SMS
{
    /**
     * @var SMS $instance
     */
    public static $instance = null;

    /**
     * @var string $accessToken
     */
    private $accessToken;

    /**
     * @var string $refreshToken
     */
    private $refreshToken;

    /**
     * @var string $username
     */
    private $username;

    /**
     * @var string $clientId
     */
    private $clientId;

    /**
     * @var string $password
     */
    private $password;

    /**
     * @var Client $httpClient
     */
    private $httpClient;

    /**
     * @var string $baseUri
     */
    private $baseUri;

    /**
     * @var int $retryAttempts
     */
    private $retryAttempts;

    /**
     * All values required to create instance of SMS
     *
     * @param string $baseUri
     * @param string $username
     * @param string $password
     * @param string|null $accessToken
     * @param string|null $refreshToken
     * @param integer $retryAttempts
     */
    public function __construct($baseUri, $username, $password, $accessToken = null, $refreshToken = null, $retryAttempts = 3)
    {
        $this->baseUri = $baseUri;
        $this->username = $username;
        $this->password = $password;
        $this->accessToken = $accessToken;
        $this->retryAttempts = $retryAttempts;

        $this->httpClient = new \GuzzleHttp\Client([
            'base_uri' => $baseUri
        ]);
    }

    /**
     * Authenticate with SMS API
     * @param string|null username
     * @param string|null password
     * @throws mixed
     * @return array
     */
    private function authenticate($username = null, $password = null)
    {

        if ($username) {
            $this->username = $username;
        }

        if ($password) {
            $this->password = $password;
        }
        
        $data = [
            'username' => $this->username,
            'password' => $this->password
        ];

        $response = $this->post('user/authenticate', $data);
        $body = json_decode((string) $response->getBody());

        $this->accessToken = $body->access_token;
        $this->clientId = $body->client_id;
        $this->refreshToken = $body->refresh_token;

        return [
            'accessToken' => $this->accessToken,
            'clientId' => $this->clientId,
            'refreshToken' => $this->refreshToken
        ];

    }

    /**
     * Refresh Access Token using refresh Token
     */
    private function refreshToken()
    {
        if (! $this->refreshToken) {
            $this->authenticate();
        } else {
            $data = [
                'client_id' => $this->clientId,
                'refresh_token' => $this->refreshToken
            ];
            try{
                $response = $this->post('user/refresh_token', $data);
                $body = json_decode((string) $response->getBody());
                $this->accessToken = $body->access_token;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                throw new \Exception('Unable to refresh token. ' . $e->getMessage());
            }
        }

    }

    /**
     * Make SMS delivery request to API
     * @param string $phoneNumber Claimant's phone number
     * @param string $activityCode  Activity code
     * @param int $eventDate   UNIX time incident was logged
     * @return string|null
     * @throws \Exception
     */
    public function deliver($phoneNumber, $incidentId, $activityCode, $eventDate)
    {
        // If the phone number isn't a real phone number return null
        // Real phone numbers are 10-digit numbers or 11-digit numbers beginning with 1, but
        // not numbers that just repeat the same digit, e.g. 5555555555
        if ((! preg_match('/(?=^[1][0-9]\d{9}$|^[0-9]\d{9}$)(?!^([0-9])\1*$)/', $phoneNumber))) {
            return null;
        }

        if (! $this->accessToken) {
            $this->authenticate();
        }

        $data = [
	        'phone_number' => $phoneNumber,
            'incident_id' => $incidentId, 
            'activity_code' =>  $activityCode,
            'event_date' => date("Y-m-d", $eventDate)
        ];

        $retryCount = 0;
        while (true) {
            try {
                $response = $this->post('sms/message/deliver', $data);
                $body = json_decode((string) $response->getBody());
                return $body;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                if ($e->getResponse()->getStatusCode() == 401 && preg_match('/Token/', $e->getResponse()->getBody())) {
                    if (++$retryCount >= $this->retryAttempts) {
                        throw new \Exception('Authentication Error: Too many retries');
                    }
        
                    $this->authenticate();
                } else {
                    throw new \Exception('Unknown Delivery Error');
                }
            }
        }   
    }

    /**
     * Get HTTP request headers
     * @return array    request headers
     */
    private function getHeaders()
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        if ($this->accessToken) {
            $headers['Authorization'] = $this->accessToken;
        }

        return $headers;

    }

    /**
     * Http POST
     * @param string $action
     * @param array $data
     * @return mixed    response
     * @throws mixed
     */
    private function post($action, $data)
    {

        $headers = $this->getHeaders();

        return $this->httpClient->post($action, [
            'form_params' => $data,
            'debug' => false,
            'headers' => $headers
        ]);

    }

}
