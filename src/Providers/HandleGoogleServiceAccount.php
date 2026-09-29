<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Google\Auth\Credentials\ServiceAccountCredentials;

use function time;

/**
 * Bearer authentication for the Google Vertex providers. Service-account
 * tokens expire after about an hour, so the token is fetched on demand and
 * renewed shortly before it expires: long-lived processes keep working, and
 * building the provider needs no network.
 */
trait HandleGoogleServiceAccount
{
    protected ServiceAccountCredentials $credentials;

    protected function useServiceAccount(string $pathJsonCredentials): void
    {
        $this->credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
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
        $token = $this->credentials->getLastReceivedToken();

        // A 60-second margin keeps a token from expiring while a request is in flight
        if ($token === null || ($token['expires_at'] ?? 0) - 60 <= time()) {
            $token = $this->credentials->fetchAuthToken();
        }

        return $token['access_token'];
    }
}
