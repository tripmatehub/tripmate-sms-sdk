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
     * Set up connection requirements
     * @param string $baseUri
     * @param string $username
     * @param string $password
     * @param string|null $accessToken
     * @param string|null $accessToken
     */
    public function configure($baseUri, $username, $password, $accessToken = null, $refreshToken = null)
    {
        $this->baseUri = $baseUri;
        $this->username = $username;
        $this->password = $password;
        $this->accessToken = $accessToken;

        $this->httpClient = new \GuzzleHttp\Client([
            'base_uri' => $baseUri
        ]);
    }

    /**
     * SMS is a singleton.
     * Get instance, instantiate as needed
     * @return SMS
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new SMS();
        }

        return self::$instance;

    }

    /**
     * Authenticate iwth SMS API
     * @param string|null username
     * @param string|null password
     * @throws mixed
     * @return array
     */
    public function authenticate($username = null, $password = null)
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
        $this->client_id = $body->client_id;
        $this->refresh_token = $body->refresh_token;

        return [
            'accessToken' => $this->accessToken,
            'clientId' => $this->clientId,
            'refreshToken' => $this->refreshToken
        ];

    }

    /**
     * Refresh Access Token using refresh Token
     */
    public function refreshToken()
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
                $this->access_token = $body->access_token;
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
    public function deliver($phoneNumber, $incidentId, $activityCode, $eventDate, $retryCount=0)
    {
        // If the phone number isn't a real phone number return null
        // Real phone numbers are 10-digit numbers or 11-digit numbers beginning with 1, but
        // not numbers that just repeat the same digit, e.g. 5555555555
        if ((! preg_match('/(?=^[1][0-9]\d{9}$|^[0-9]\d{9}$)(?!^([0-9])\1*$)/', $phoneNumber))) {
            return null;
        }

        if ($retryCount >= 3) {
            throw new \Exception('Authentication Error: Too many retries');
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

        try {
            $response = $this->post('sms/message/deliver', $data);
            $body = json_decode((string) $response->getBody());
            return $body;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401 && preg_match('/Token/', $e->getResponse()->getBody())) {
                $this->authenticate();
                $this->deliver($phoneNumber, $activityCode, $eventDate, ++$retryCount);
            } else {
                throw new \Exception('Unknown Delivery Error');
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
