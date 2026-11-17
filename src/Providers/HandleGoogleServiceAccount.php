<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_map;
use function implode;
use function time;

/**
 * Bearer authentication for the Google Vertex providers. Service-account
 * tokens expire after about an hour, so the token is fetched on demand and
 * renewed shortly before it expires: long-lived processes keep working, and
 * building the provider needs neither the network nor the credentials file.
 */
trait HandleGoogleServiceAccount
{
    protected ServiceAccountCredentials $credentials;

    /**
     * Sends the OAuth token request; null leaves it to google/auth's default Guzzle client.
     */
    protected ?HttpClientInterface $authHttpClient = null;

    protected function credentials(): ServiceAccountCredentials
    {
        return $this->credentials ??= new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $this->pathJsonCredentials
        );
    }

    /**
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        return [...$this->httpHeaders, 'Authorization' => 'Bearer ' . $this->accessToken()];
    }

    protected function accessToken(): string
    {
        $credentials = $this->credentials();
        $token = $credentials->getLastReceivedToken();

        // A 60-second margin keeps a token from expiring while a request is in flight
        if ($token === null || ($token['expires_at'] ?? 0) - 60 <= time()) {
            $token = $this->authHttpClient instanceof HttpClientInterface
                ? $credentials->fetchAuthToken($this->sendTokenRequest(...))
                : $credentials->fetchAuthToken();
        }

        return $token['access_token'];
    }

    /**
     * A google/auth HTTP handler backed by the injected client. google/auth leaves
     * error statuses to its handler, whose default Guzzle client throws Guzzle's
     * RequestException; the same exception is thrown here so token errors surface
     * identically with or without an injected client.
     *
     * @param array<string, mixed> $options google/auth transport options, only used for mTLS token sources
     */
    protected function sendTokenRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        try {
            $response = $this->authHttpClient->request(new HttpRequest(
                HttpMethod::from($request->getMethod()),
                (string) $request->getUri(),
                array_map(static fn (array $values): string => implode(', ', $values), $request->getHeaders()),
                (string) $request->getBody(),
            ));
        } catch (HttpException $exception) {
            if ($exception->response === null) {
                throw $exception;
            }

            $response = $exception->response;
            throw RequestException::create($request, new Response($response->statusCode, $response->headers, $response->body), $exception);
        }

        return new Response($response->statusCode, $response->headers, $response->body);
    }
}
