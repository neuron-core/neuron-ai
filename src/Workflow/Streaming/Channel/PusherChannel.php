<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use JsonException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

use function array_map;
use function hash_hmac;
use function implode;
use function json_encode;
use function md5;
use function strlen;
use function time;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final class PusherChannel extends AbstractChannel
{
    use HasHttpClient;

    public function __construct(
        protected string $channel,
        protected string $appId,
        protected string $key,
        protected string $secret,
        string $host = 'https://api-mt1.pusher.com',
        protected int $maxRequestBytes = 10_000,
        protected int $batchSize = 10,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri($host)
            ->withHeaders(['Content-Type' => 'application/json']);
    }

    protected function batchSize(): int
    {
        return $this->batchSize;
    }

    /** The request body minus the batch envelope. */
    protected function budget(): int
    {
        return $this->maxRequestBytes - strlen('{"batch":[]}');
    }

    /**
     * A batch item plus its separating comma.
     *
     * @throws JsonException
     */
    protected function size(ProtocolEvent $event): int
    {
        return strlen($this->item($event)) + 1;
    }

    /**
     * @throws JsonException
     */
    protected function deliver(array $events): void
    {
        $body = '{"batch":[' . implode(',', array_map($this->item(...), $events)) . ']}';

        $this->httpClient->request(new HttpRequest(HttpMethod::POST, $this->signedUri($body), body: $body));
    }

    /**
     * Pusher wants data as a string, encoded here so the signature covers the
     * exact bytes on the wire; unicode stays escaped as on the SSE path.
     *
     * @throws JsonException
     */
    protected function item(ProtocolEvent $event): string
    {
        return json_encode([
            'channel' => $this->channel,
            'name' => $event->type,
            'data' => json_encode($event->data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Pusher HTTP API authentication, version 1.0: an HMAC-SHA256 over the
     * method, the path and the key-sorted, unescaped query string.
     */
    protected function signedUri(string $body): string
    {
        $path = "/apps/{$this->appId}/batch_events";
        $query = "auth_key={$this->key}&auth_timestamp=" . time() . "&auth_version=1.0&body_md5=" . md5($body);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", $this->secret);

        return "{$path}?{$query}&auth_signature={$signature}";
    }
}
