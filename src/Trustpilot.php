<?php

namespace Justijndepover\Trustpilot;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Justijndepover\Trustpilot\Exceptions\ApiException;
use Justijndepover\Trustpilot\Exceptions\CouldNotAquireAccessTokenException;
use Justijndepover\Trustpilot\Exceptions\TooManyRequestsException;
use Justijndepover\Trustpilot\Exceptions\UnauthorizedException;
use Justijndepover\Trustpilot\Exceptions\UnknownResourceException;

class Trustpilot
{
    /**
     * @var string
     */
    private $baseUrl = 'https://api.trustpilot.com/v1';

    /**
     * @var string
     */
    private $authUrl = 'https://authenticate.trustpilot.com';

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var Client|null
     */
    private $client;

    /**
     * @var callable(Connection)
     */
    private $tokenUpdateCallback;

    /**
     * @var string
     */
    private $redirectUri;

    /**
     * @var string
     */
    private $authorizationCode;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var string
     */
    private $refreshToken;

    /**
     * @var int
     */
    private $tokenExpiresAt;

    public function __construct(string $clientId, string $clientSecret, string $redirectUri)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;

        $this->client = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'verify' => true,
        ]);
    }

    public function redirectForAuthorizationUrl(): string
    {
        return $this->authUrl
            . '?client_id=' . $this->clientId
            . '&response_type=code'
            . '&redirect_uri=' . $this->redirectUri;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId($clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret($clientSecret): void
    {
        $this->clientSecret = $clientSecret;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function setRedirectUri($redirectUri): void
    {
        $this->redirectUri = $redirectUri;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorizationCode;
    }

    public function setAuthorizationCode($authorizationCode): void
    {
        $this->authorizationCode = $authorizationCode;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken($accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken($refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getTokenExpiresAt(): ?int
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt($tokenExpiresAt): void
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
    }

    public function shouldAuthorize(): bool
    {
        return empty($this->authorizationCode) && empty($this->refreshToken);
    }

    public function shouldRefreshToken(): bool
    {
        return empty($this->accessToken) || $this->tokenHasExpired();
    }

    private function tokenHasExpired(): bool
    {
        if (empty($this->tokenExpiresAt)) {
            return true;
        }

        return ($this->tokenExpiresAt - 60) < time();
    }

    public function connect(): void
    {
        if ($this->shouldAuthorize()) {
            header("Location: {$this->redirectForAuthorizationUrl()}");
            exit;
        }

        if ($this->shouldRefreshToken()) {
            $this->acquireAccessToken();
        }
    }

    public function setTokenUpdateCallback(callable $callback): void
    {
        $this->tokenUpdateCallback = $callback;
    }

    public function get(string $endpoint, array $parameters = [])
    {
        try {
            $request = $this->createRequest('GET', $endpoint, null, $parameters);
            $response = $this->client->send($request);

            return $this->parseResponse($response);
        } catch (ClientException $e) {
            $this->parseExceptionForErrorMessages($e);
        } catch (Exception $e) {
            throw ApiException::make($e->getCode(), $e->getMessage());
        }
    }

    public function post(string $endpoint, array $body, array $parameters = [])
    {
        $body = json_encode($body);

        try {
            $request = $this->createRequest('POST', $endpoint, $body, $parameters);
            $response = $this->client->send($request);

            return $this->parseResponse($response);
        } catch (ClientException $e) {
            $this->parseExceptionForErrorMessages($e);
        } catch (Exception $e) {
            throw ApiException::make($e->getCode(), $e->getMessage());
        }
    }

    private function createRequest($method, $endpoint, $body = null, array $parameters = [], array $headers = [])
    {
        $endpoint = $this->buildUrl($endpoint);

        $headers = array_merge($headers, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        // If access token is not set or token has expired, acquire new token
        if (empty($this->accessToken) || $this->tokenHasExpired()) {
            $this->acquireAccessToken();
        }

        // If we have a token, sign the request
        if (! $this->shouldAuthorize() && ! empty($this->accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        // Create param string
        if (! empty($parameters)) {
            $endpoint .= '?' . http_build_query($parameters);
        }

        // Create the request
        $request = new Request($method, $endpoint, $headers, $body);

        return $request;
    }

    private function buildUrl(string $endpoint): string
    {
        return 'https://api.trustpilot.com/v1/' . ltrim($endpoint, '/');
    }

    private function parseResponse(Response $response)
    {
        try {
            if ($response->getStatusCode() === 204) {
                return [];
            }

            Message::rewindBody($response);
            $json = json_decode($response->getBody()->getContents(), true);

            return $json;
        } catch (\RuntimeException $e) {
            throw new Exception($e->getMessage());
        }
    }

    private function parseExceptionForErrorMessages(ClientException $e): void
    {
        $response = json_decode($e->getResponse()->getBody()->getContents());

        if ($response->errors[0]->status == 401) {
            throw UnauthorizedException::make($response->errors[0]->status, $response->errors[0]->title);
        }

        if ($response->errors[0]->status == 404) {
            throw UnknownResourceException::make($response->errors[0]->status, $response->errors[0]->title);
        }

        if ($response->errors[0]->status == 429) {
            throw TooManyRequestsException::make($response->errors[0]->status, $response->errors[0]->title);
        }

        throw ApiException::make($response->errors[0]->status, $response->errors[0]->title);
    }

    private function acquireAccessToken(): void
    {
        try {
            // If refresh token not yet acquired, do token request
            if (empty($this->refreshToken)) {
                $data = [
                    'form_params' => [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'code' => $this->authorizationCode,
                        'grant_type' => 'authorization_code',
                        'redirect_uri' => $this->redirectUri,
                    ],
                ];
                $url = $this->baseUrl . '/oauth/oauth-business-users-for-applications/accesstoken';
            } else { // else do refresh token request
                $data = [
                    'form_params' => [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'refresh_token' => $this->refreshToken,
                        'grant_type' => 'refresh_token',
                    ],
                ];
                $url = $this->baseUrl . '/oauth/oauth-business-users-for-applications/refresh';
            }

            $response = $this->client->post($url, $data);

            Message::rewindBody($response);
            $body = json_decode($response->getBody()->getContents(), true);

            $this->accessToken = $body['access_token'];
            $this->refreshToken = $body['refresh_token'];
            $this->tokenExpiresAt = time() + $body['expires_in'];

            if (is_callable($this->tokenUpdateCallback)) {
                call_user_func($this->tokenUpdateCallback, $this);
            }
        } catch (ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents());

            throw CouldNotAquireAccessTokenException::make($response->errors[0]->status, $response->errors[0]->title);
        } catch (Exception $e) {
            throw ApiException::make($e->getCode(), $e->getMessage());
        }
    }
}
