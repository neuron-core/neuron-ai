<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Psr7\Response;
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
            $token = $credentials->fetchAuthToken($this->sendTokenRequest(...));
        }

        return $token['access_token'];
    }

    /**
     * A google/auth HTTP handler that sends the token request through the provider's
     * own client, so it follows that client's runtime (blocking or async). An error
     * status throws HttpException, like any other failing Vertex call.
     *
     * @param array<string, mixed> $options google/auth transport options, only used for mTLS token sources
     */
    protected function sendTokenRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        $response = $this->httpClient->request(new HttpRequest(
            HttpMethod::from($request->getMethod()),
            (string) $request->getUri(),
            array_map(static fn (array $values): string => implode(', ', $values), $request->getHeaders()),
            (string) $request->getBody(),
            // The client timeout is sized for LLM calls; a token exchange should fail fast.
            timeout: 10.0,
        ));

        return new Response($response->statusCode, $response->headers, $response->body);
    }
}
