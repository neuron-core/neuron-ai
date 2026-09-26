<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pusher\Pusher;

use function str_repeat;

class PusherFragmentedEventNameTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function invalidNames(): array
    {
        return [
            'reserved, small' => ['pusher:subscribe', 10],
            'reserved, fragmented' => ['pusher:subscribe', 20_000],
            'empty, small' => ['', 10],
            'empty, fragmented' => ['', 20_000],
            'too long, small' => [str_repeat('a', 201), 10],
            'too long, fragmented' => [str_repeat('a', 201), 20_000],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_an_invalid_event_name_is_rejected_whatever_the_payload_size(string $name, int $payloadBytes): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}')]));
        $stack->push(Middleware::history($sent));
        $pusher = new Pusher('key', 'secret', 'app', [], new Client(['handler' => $stack]));
        $channel = new PusherChannel($pusher, 'chat.42', batchSize: 1);

        try {
            $channel->send(new ProtocolEvent($name, ['output' => str_repeat('x', $payloadBytes)]));
            $this->fail('An invalid Pusher event name must be rejected for a large payload as for a small one.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Pusher event names must be 1–200 bytes and cannot start with pusher:.', $e->getMessage());
            $this->assertSame([], $sent);
        }
    }
}
