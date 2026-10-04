<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;

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
            $token = $credentials->fetchAuthToken();
        }

        return $token['access_token'];
    }
}
