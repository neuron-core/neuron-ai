<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use GuzzleHttp\Client;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use Pusher\Pusher;
use NeuronAI\Tests\Workflow\Channel\Stub\RecordingRedis;
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;
use Throwable;

use function array_fill;
use function array_map;
use function in_array;
use function json_decode;
use function str_repeat;
use function count;
use function base64_encode;

/** Real channel encoders with in-memory I/O; no broker credentials or services. */
final class ChannelFixture
{
    /** @return array<string, mixed> */
    public static function run(string $transport, string $outcome, bool $failDelivery = false, ?string $protocol = null): array
    {
        if (!in_array($transport, ['pusher', 'pusher-encrypted', 'redis'], true)
            || !in_array($outcome, ['completed', 'interrupted', 'failed'], true)) {
            throw new InvalidArgumentException('Unknown channel fixture transport or outcome.');
        }

        $sent = [];
        $redis = null;
        $pusher = null;
        $encrypted = $transport === 'pusher-encrypted';
        $destination = $encrypted ? 'private-encrypted-frontend-test' : 'private-frontend-test';
        if ($transport !== 'redis') {
            $responses = array_fill(0, 1_000, new Response(200, [], '{}'));
            if ($failDelivery) {
                $responses[0] = new Response(503, [], 'transport unavailable');
            }
            $stack = HandlerStack::create(new MockHandler($responses));
            $stack->push(Middleware::history($sent));
            $options = ['timeout' => 5];
            if ($encrypted) {
                $options['encryption_master_key_base64'] = base64_encode(str_repeat('k', 32));
            }
            $pusher = new Pusher('key', 'secret', 'app', $options, new Client(['handler' => $stack]));
            $channel = new PusherChannel($pusher, $destination, maxRequestBytes: 2_000);
        } else {
            $redis = new RecordingRedis();
            $channel = new RedisChannel($redis, 'frontend-test');
        }

        $events = [
            new ProtocolEvent('text-delta', ['id' => 'message-1', 'delta' => 'Hello']),
            new ProtocolEvent('tool-output-available', [
                'toolCallId' => 'call-1',
                'output' => ['text' => str_repeat('Napoli è bella / "ciao" 🌍 ', 400), 'values' => [false, 0, null]],
            ]),
            new ProtocolEvent('tool-output-available', [
                'toolCallId' => 'call-2',
                'output' => ['text' => str_repeat('Second result 日本語 ', 300), 'type' => 'payload-type'],
            ]),
        ];
        if ($protocol !== null) {
            $adapter = match ($protocol) {
                'agui' => new AGUIAdapter('thread-channel', 'run-channel'),
                'vercel' => new VercelAIAdapter(),
                default => throw new InvalidArgumentException('Unknown channel fixture protocol.'),
            };
            $events = [
                ...$adapter->start(),
                ...$adapter->transform(new TextChunk('message-1', str_repeat('Hello 日本語 🌍 ', 400))),
                ...$adapter->end(),
            ];
        }
        $errors = 0;
        foreach ($events as $event) {
            try {
                $channel->send($event);
            } catch (Throwable) {
                ++$errors;
            }
        }
        $state = new WorkflowState();
        $state->setExecutionMetadata('workflow-frontend', 'run-frontend', 1);
        match ($outcome) {
            'completed' => $channel->completed($state, 'workflow-frontend'),
            'interrupted' => $channel->interrupted($state),
            'failed' => $channel->failed(new RuntimeException('internal secret error details'), 'workflow-frontend'),
        };

        $frames = [];
        $wireEvents = [];
        foreach ($sent as $exchange) {
            if ($exchange['response']->getStatusCode() >= 400) {
                continue;
            }
            foreach (json_decode((string) $exchange['request']->getBody(), true)['batch'] as $item) {
                $wireEvents[] = ['event' => $item['name'], 'data' => json_decode($item['data'], true)];
                if (!$encrypted) {
                    $frames[] = json_decode($item['data'], true);
                }
            }
        }
        if ($redis instanceof \NeuronAI\Tests\Workflow\Channel\Stub\RecordingRedis) {
            $frames = array_map(static fn (array $publication): array => json_decode($publication['message'], true), $redis->published);
        }

        return [
            'frames' => $frames,
            'wireEvents' => $wireEvents,
            'channel' => $destination,
            'authorization' => $encrypted ? json_decode($pusher->authorizeChannel($destination, '123.456'), true) : null,
            'expected' => [...array_map(static fn (ProtocolEvent $event): array => [
                'type' => $event->type,
                'data' => $event->data,
            ], $events), ['type' => 'stream.' . $outcome, 'data' => ['workflowId' => 'workflow-frontend']]],
            'errors' => $errors,
            'requests' => count($sent),
        ];
    }
}
