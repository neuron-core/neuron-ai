<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function base64_encode;
use function count;
use function hash_hmac;
use function implode;
use function json_encode;
use function md5;
use function str_split;
use function strlen;
use function time;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Pushes a run segment to a Pusher Channels channel through the HTTP API, so
 * a browser subscribed to it follows a run driven by another process (a queue
 * worker, a resumed run). Any server speaking the Pusher protocol works
 * through $host: Pusher clusters, Laravel Reverb, Soketi.
 *
 * Wire contract, in stream order:
 *  - every protocol event is a Pusher event named by its type, carrying its data;
 *  - the segment lifecycle is stream.suspended / stream.completed / stream.failed,
 *    carrying the workflowId only: what a client learns about an error is the
 *    adapter's decision, through its own error frame;
 *  - an event that does not fit one request travels as consecutive
 *    stream.fragment events {type, index, total, part}, part being a base64
 *    slice of the event's JSON data; the client concatenates and parses the last.
 *
 * Requests go through the batch_events endpoint, up to $batchSize events and
 * $maxRequestBytes per request: one number honours Pusher's per-event ceiling
 * and Reverb's per-request one. Subscribers receive individual events either
 * way. A rejected request raises HttpException, which the Workflow reports as
 * a ChannelError without failing the run.
 */
final class PusherChannel implements StreamingChannelInterface
{
    use HasHttpClient;

    protected const ENVELOPE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

    /** Room for index and total to grow beyond the single digits of the measured empty envelope. */
    protected const COUNTER_DIGITS = 12;

    /** @var string[] Batch items, each already a JSON object literal, in send order. */
    protected array $pending = [];

    protected int $pendingBytes = 0;

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

    public function send(ProtocolEvent $event): void
    {
        $this->trigger($event->type, $event->data);
    }

    public function suspended(WorkflowState $state): void
    {
        $this->trigger('stream.suspended', ['workflowId' => $state->getWorkflowId()]);
        $this->flush();
    }

    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->trigger('stream.completed', ['workflowId' => $workflowId]);
        $this->flush();
    }

    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->trigger('stream.failed', ['workflowId' => $workflowId]);
        $this->flush();
    }

    /**
     * Fragmentation happens before buffering, so the batch only ever sees
     * items that fit one request on their own.
     *
     * @param array<string, mixed> $data
     */
    protected function trigger(string $name, array $data): void
    {
        // Unicode stays escaped so a fragment decodes with atob(), which only
        // carries ASCII; invalid UTF-8 becomes U+FFFD as on the SSE path.
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $item = $this->item($name, $encoded);

        if (strlen($item) <= $this->budget()) {
            $this->enqueue($item);
            return;
        }

        $sliceBytes = $this->budget() - strlen($this->fragment($name, 0, 0, '')) - self::COUNTER_DIGITS;
        $parts = str_split(base64_encode($encoded), $sliceBytes);
        foreach ($parts as $index => $part) {
            $this->enqueue($this->fragment($name, $index, count($parts), $part));
        }
    }

    /**
     * Base64 keeps a slice the same size once wrapped in JSON: nothing in it
     * is escaped (slashes included, see ENVELOPE_FLAGS), so a fragment item
     * is exactly the slice plus the envelope measured in trigger().
     */
    protected function fragment(string $type, int $index, int $total, string $part): string
    {
        return $this->item('stream.fragment', json_encode(
            ['type' => $type, 'index' => $index, 'total' => $total, 'part' => $part],
            self::ENVELOPE_FLAGS,
        ));
    }

    protected function item(string $name, string $encodedData): string
    {
        return json_encode(
            ['channel' => $this->channel, 'name' => $name, 'data' => $encodedData],
            self::ENVELOPE_FLAGS,
        );
    }

    /**
     * One rule for every item, fragments included: what would not fit the
     * request goes after a flush, and a full batch leaves at once.
     */
    protected function enqueue(string $item): void
    {
        if ($this->pendingBytes + strlen($item) > $this->budget()) {
            $this->flush();
        }

        $this->pending[] = $item;
        $this->pendingBytes += strlen($item) + 1; // the separating comma

        if (count($this->pending) >= $this->batchSize) {
            $this->flush();
        }
    }

    protected function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $body = '{"batch":[' . implode(',', $this->pending) . ']}';
        $this->pending = [];
        $this->pendingBytes = 0;

        $this->httpClient->request(new HttpRequest(HttpMethod::POST, $this->signedUri($body), body: $body));
    }

    /** The request body minus the batch envelope. */
    protected function budget(): int
    {
        return $this->maxRequestBytes - strlen('{"batch":[]}');
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
