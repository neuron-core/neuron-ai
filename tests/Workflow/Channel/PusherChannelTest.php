<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use GuzzleHttp\Client;
use Pusher\Pusher;
use Pusher\ApiErrorException;
use Throwable;
use LengthException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use InvalidArgumentException;
use NeuronAI\Tests\Workflow\Channel\Stub\CountingPayload;

use function array_map;
use function array_merge;
use function count;
use function hash_hmac;
use function iterator_to_array;
use function json_decode;
use function md5;
use function parse_str;
use function range;
use function str_repeat;
use function strlen;
use function array_unique;
use function base64_decode;
use function base64_encode;
use function implode;
use function sodium_crypto_secretbox_open;
use function strtr;

class PusherChannelTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $sent = [];

    protected Pusher $pusher;

    protected function channel(int $batchSize = 10, int $maxRequestBytes = 10_000, bool $encrypted = false): PusherChannel
    {
        $this->sent = [];
        $responses = array_map(static fn (int $ignored): Response => new Response(200, [], '{}'), range(1, 1_000));
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $options = ['cluster' => 'eu', 'timeout' => 5];
        if ($encrypted) {
            $options['encryption_master_key_base64'] = base64_encode(str_repeat('k', 32));
        }
        $this->pusher = new Pusher('app-key', 'app-secret', 'app-id', $options, new Client(['handler' => $stack]));
        return new PusherChannel(
            pusher: $this->pusher,
            channel: $encrypted ? 'private-encrypted-chat.42' : 'chat.42',
            maxRequestBytes: $maxRequestBytes,
            batchSize: $batchSize,
        );
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    /**
     * The batch items of every request, in request order.
     *
     * @return array<int, array<int, array{channel: string, name: string, data: string}>>
     */
    protected function batches(): array
    {
        return array_map(
            static fn (array $exchange): array => json_decode((string) $exchange['request']->getBody(), true)['batch'],
            $this->sent,
        );
    }

    /**
     * Every event name in HTTP submission order.
     *
     * @return string[]
     */
    protected function names(): array
    {
        return array_map(static fn (array $item): string => $item['name'], array_merge(...$this->batches()));
    }

    protected function assertRequestsWithinBudget(int $maxRequestBytes, int $batchSize): void
    {
        foreach ($this->sent as $exchange) {
            $this->assertLessThanOrEqual($maxRequestBytes, strlen((string) $exchange['request']->getBody()));
        }
        foreach ($this->batches() as $batch) {
            $this->assertLessThanOrEqual($batchSize, count($batch));
        }
    }

    public function test_send_triggers_a_signed_request_containing_the_shared_envelope(): void
    {
        $channel = $this->channel(batchSize: 1);
        $channel->send(new ProtocolEvent('text-delta', ['id' => 'msg_1', 'delta' => 'Hello']));
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        $body = (string) $request->getBody();
        parse_str($request->getUri()->getQuery(), $query);
        $item = $this->batches()[0][0];
        $envelope = json_decode($item['data'], true);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('api-eu.pusher.com', $request->getUri()->getHost());
        $this->assertSame('/apps/app-id/batch_events', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('chat.42', $item['channel']);
        $this->assertSame('text-delta', $item['name']);
        $this->assertSame('text-delta', $envelope['type']);
        $this->assertSame(0, $envelope['sequence']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $envelope['streamId']);
        $this->assertSame(['id' => 'msg_1', 'delta' => 'Hello'], $envelope['data']);
        $this->assertSame(md5($body), $query['body_md5']);
        $stringToSign = "POST\n/apps/app-id/batch_events\n"
            . "auth_key=app-key&auth_timestamp={$query['auth_timestamp']}&auth_version=1.0&body_md5={$query['body_md5']}";
        $this->assertSame(hash_hmac('sha256', $stringToSign, 'app-secret'), $query['auth_signature']);
    }

    public function test_default_batching_waits_for_ten_events_and_flushes_at_completion(): void
    {
        $channel = $this->channel();
        foreach (range(1, 9) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        }
        $this->assertSame([], $this->sent);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $this->assertCount(1, $this->sent);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([10, 1, 1], array_map(count(...), $this->batches()));
        $this->assertRequestsWithinBudget(10_000, 10);
    }

    public function test_transport_encoding_and_batch_wrapper_obey_the_request_budget(): void
    {
        $channel = $this->channel(batchSize: 10, maxRequestBytes: 1_000);
        foreach (range(1, 5) as $ignored) {
            $channel->send(new ProtocolEvent('tool-output', ['output' => str_repeat('"/è', 100)]));
        }
        $channel->completed($this->state(), 'wf-1');

        $this->assertContains('stream.fragment', $this->names());
        $this->assertRequestsWithinBudget(1_000, 10);
    }

    public function test_raising_request_budget_does_not_allow_oversized_pusher_events(): void
    {
        $channel = $this->channel(batchSize: 10, maxRequestBytes: 100_000);
        $channel->send(new ProtocolEvent('tool-output', ['output' => str_repeat('x', 25_000)]));
        $channel->completed($this->state(), 'wf-1');
        $this->assertContains('stream.fragment', $this->names());
        foreach ($this->batches() as $batch) {
            foreach ($batch as $item) {
                $this->assertLessThanOrEqual(10_000, strlen($item['data']));
            }
        }
        $this->assertRequestsWithinBudget(100_000, 10);
    }

    public function test_payload_serialization_is_not_repeated_for_measurement_and_delivery(): void
    {
        $payload = new CountingPayload();
        $channel = $this->channel(batchSize: 1);
        $channel->send(new ProtocolEvent('tool-output', ['output' => $payload]));
        $this->assertSame(1, $payload->calls);
    }

    public function test_invalid_transport_configuration_is_rejected(): void
    {
        foreach ([['batchSize' => 0], ['batchSize' => 11], ['maxRequestBytes' => 11], ['channel' => 'invalid/name'], ['channel' => str_repeat('a', 165)]] as $options) {
            try {
                new PusherChannel(...[...['pusher' => new Pusher('key', 'secret', 'app'), 'channel' => 'private-test'], ...$options]);
                $this->fail('Expected invalid Pusher configuration.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_streams_an_agent_run_with_protocol_payloads_preserved(): void
    {
        $channel = $this->channel();
        $agent = Agent::make()->setStreamAdapter(new VercelAIAdapter())->setChannel($channel);
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world from Pusher, streamed in small chunks')))->setStreamChunkSize(5));
        $pulled = iterator_to_array($agent->stream(new UserMessage('Hi')), false);
        $items = array_merge(...$this->batches());
        $this->assertSame(
            [...array_map(static fn (ProtocolEvent $event): string => $event->type, $pulled), 'stream.completed'],
            array_map(static fn (array $item): string => $item['name'], $items),
        );
        foreach ($pulled as $index => $event) {
            $envelope = json_decode($items[$index]['data'], true);
            $this->assertSame($event->data, $envelope['data']);
            $this->assertSame($index, $envelope['sequence']);
        }
        $this->assertRequestsWithinBudget(10_000, 10);
    }

    public function test_custom_endpoints_and_http_configuration_belong_to_the_injected_sdk(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}'), new Response(200, [], '{}')]));
        $stack->push(Middleware::history($sent));
        $client = new Client(['handler' => $stack, 'connect_timeout' => 2.5]);
        $first = new Pusher('key', 'secret', 'app', ['host' => 'reverb.example', 'port' => 8080, 'scheme' => 'http', 'timeout' => 7], $client);
        $second = new Pusher('key', 'secret', 'app', ['host' => 'soketi.example', 'port' => 6001, 'scheme' => 'http', 'timeout' => 9], $client);
        (new PusherChannel($first, 'private-first', batchSize: 1))->send(new ProtocolEvent('text-delta'));
        (new PusherChannel($second, 'private-second', batchSize: 1))->send(new ProtocolEvent('text-delta'));

        $this->assertSame('reverb.example', $sent[0]['request']->getUri()->getHost());
        $this->assertSame(8080, $sent[0]['request']->getUri()->getPort());
        $this->assertSame('http', $sent[0]['request']->getUri()->getScheme());
        $this->assertSame(7, $sent[0]['options']['timeout']);
        $this->assertSame(2.5, $sent[0]['options']['connect_timeout']);
        $this->assertSame('soketi.example', $sent[1]['request']->getUri()->getHost());
        $this->assertSame(6001, $sent[1]['request']->getUri()->getPort());
        $this->assertSame(9, $sent[1]['options']['timeout']);
    }

    /** @param array{channel: string, name: string, data: string} $item
     * @return array<string, mixed>
     */
    protected function decrypt(array $item): array
    {
        $authorization = json_decode($this->pusher->authorizeChannel($item['channel'], '123.456'), true);
        $encrypted = json_decode($item['data'], true);
        $plaintext = sodium_crypto_secretbox_open(
            base64_decode($encrypted['ciphertext']),
            base64_decode($encrypted['nonce']),
            base64_decode($authorization['shared_secret']),
        );
        $this->assertNotFalse($plaintext);
        return json_decode($plaintext, true);
    }

    public function test_encrypted_batches_decrypt_to_envelopes_and_use_unique_nonces(): void
    {
        $channel = $this->channel(encrypted: true);
        foreach (range(1, 10) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'confidential']));
        }
        $channel->completed($this->state(), 'wf-1');
        $this->assertSame([10, 1], array_map(count(...), $this->batches()));
        $items = array_merge(...$this->batches());
        $nonces = [];
        foreach ($items as $index => $item) {
            $this->assertStringNotContainsString('confidential', $item['data']);
            $encrypted = json_decode($item['data'], true);
            $nonces[] = $encrypted['nonce'];
            $envelope = $this->decrypt($item);
            $this->assertSame($index, $envelope['sequence']);
            $this->assertSame($item['name'], $envelope['type']);
            $this->assertSame($index === 10 ? ['workflowId' => 'wf-1'] : ['delta' => 'confidential'], $envelope['data']);
        }
        $this->assertCount(count($nonces), array_unique($nonces));
        $this->assertRequestsWithinBudget(10_000, 10);
    }

    public function test_encrypted_fragments_respect_event_and_request_limits_and_reassemble(): void
    {
        foreach ([1_500, 10_000, 100_000] as $budget) {
            $channel = $this->channel(maxRequestBytes: $budget, encrypted: true);
            $data = ['output' => str_repeat('日本語 / "è" 🌍 ', 600), 'values' => [false, 0, null]];
            $channel->send(new ProtocolEvent('tool-output', $data));
            $channel->completed($this->state(), 'wf-1');
            $parts = [];
            foreach (array_merge(...$this->batches()) as $item) {
                $this->assertLessThanOrEqual(10_000, strlen($item['data']));
                $envelope = $this->decrypt($item);
                if ($envelope['type'] === 'stream.fragment') {
                    $this->assertSame(0, $envelope['sequence']);
                    $parts[$envelope['data']['index']] = $envelope['data']['part'];
                } else {
                    $this->assertSame('stream.completed', $envelope['type']);
                }
            }
            $this->assertGreaterThan(1, count($parts));
            $this->assertSame($data, json_decode(base64_decode(strtr(implode('', $parts), '-_', '+/')), true));
            $this->assertRequestsWithinBudget($budget, 10);
        }
    }

    public function test_an_encrypted_channel_without_a_key_never_sends_plaintext(): void
    {
        $this->channel();
        $channel = new PusherChannel($this->pusher, 'private-encrypted-sensitive', batchSize: 1);
        $failure = null;
        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'secret']));
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure);
        $this->assertSame([], $this->sent);
    }

    public function test_encryption_overhead_cannot_overrun_a_tiny_budget(): void
    {
        $channel = $this->channel(maxRequestBytes: 300, encrypted: true);
        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => str_repeat('a', 1_000)]));
            $this->fail('Expected impossible encrypted fragment limit.');
        } catch (LengthException) {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_failure_stops_encrypted_data_but_attempts_terminal_and_allows_reuse(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(503, [], 'unavailable'), new Response(200, [], '{}'), new Response(200, [], '{}'),
        ]));
        $stack->push(Middleware::history($sent));
        $this->pusher = new Pusher('key', 'secret', 'app', [
            'encryption_master_key_base64' => base64_encode(str_repeat('k', 32)),
        ], new Client(['handler' => $stack, 'http_errors' => false]));
        $channel = new PusherChannel($this->pusher, 'private-encrypted-test', batchSize: 1);
        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
            $this->fail('Expected SDK transport exception.');
        } catch (ApiErrorException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $this->assertCount(1, $sent);
        $channel->completed($this->state(), 'wf-1');
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $this->assertCount(3, $sent);
        $terminal = $this->decrypt(json_decode((string) $sent[1]['request']->getBody(), true)['batch'][0]);
        $reused = $this->decrypt(json_decode((string) $sent[2]['request']->getBody(), true)['batch'][0]);
        $this->assertSame('stream.completed', $terminal['type']);
        $this->assertSame(1, $terminal['sequence']);
        $this->assertSame(0, $reused['sequence']);
        $this->assertNotSame($terminal['streamId'], $reused['streamId']);
    }
}
