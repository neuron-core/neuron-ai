<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use Closure;
use NeuronAI\Exceptions\ChannelReadException;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Redis;
use RedisException;
use Throwable;

use function in_array;
use function json_decode;
use function microtime;

use const JSON_THROW_ON_ERROR;

/**
 * Reads back one segment that a RedisChannel publishes, in the process holding the client's
 * connection. Pub/Sub keeps nothing: build the publishing RedisChannel with awaitListener,
 * so the worker waits for this reader before its first event.
 */
final class RedisChannelReader
{
    protected const TERMINAL_TYPES = ['stream.completed', 'stream.interrupted', 'stream.failed'];

    protected ?string $streamId = null;

    protected ?Throwable $failure = null;

    protected float $heardAt = 0.0;

    /**
     * @param int $timeout Seconds without a message before giving up: longer than the longest
     *                     silent step of a run (a tool batch plus an inference).
     */
    public function __construct(
        protected Redis $client,
        protected string $channel,
        protected int $timeout = 300,
    ) {
    }

    /**
     * Passes each protocol event of the first segment published on the channel to $onEvent, and
     * returns at that segment's terminal event. Nested JSON objects arrive as stdClass, so every
     * event encodes back to the bytes the worker's adapter produced.
     *
     * The client's connection is closed at the end: an ended subscription can leave messages
     * unread on it. phpredis reconnects on the next command.
     *
     * @param Closure(ProtocolEvent): void $onEvent
     * @throws ChannelReadException When nothing arrives for the timeout, or the segment is incomplete.
     * @throws RedisException
     * @throws Throwable Whatever $onEvent throws.
     */
    public function listen(Closure $onEvent): void
    {
        $this->streamId = null;
        $this->failure = null;
        $this->heardAt = microtime(true);
        $readTimeout = $this->client->getOption(Redis::OPT_READ_TIMEOUT);
        $this->client->setOption(Redis::OPT_READ_TIMEOUT, $this->timeout);
        try {
            $this->client->subscribe([$this->channel], function (Redis $client, string $channel, string $message) use ($onEvent): void {
                $this->heardAt = microtime(true);
                try {
                    if ($this->receive($message, $onEvent)) {
                        return;
                    }
                } catch (Throwable $e) {
                    // Thrown from the callback, it would keep phpredis reading until the read timeout.
                    $this->failure = $e;
                }
                // $channel carries the client's key prefix, which unsubscribe() would add again.
                $client->unsubscribe([$this->channel]);
            });
        } catch (RedisException $e) {
            $this->failure ??= $this->timedOut()
                ? new ChannelReadException("Nothing arrived on Redis channel {$this->channel} within the {$this->timeout}-second timeout.", previous: $e)
                : $e;
        } finally {
            $this->client->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
            $this->client->close();
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    /**
     * Whether the segment goes on after this message.
     *
     * @throws ChannelReadException
     */
    protected function receive(string $message, Closure $onEvent): bool
    {
        // Decoded as objects: the adapters send {} for empty maps on purpose.
        $envelope = json_decode($message, false, 512, JSON_THROW_ON_ERROR);
        if ($this->streamId === null) {
            if ($envelope->sequence !== 0) {
                throw new ChannelReadException("Segment {$envelope->streamId} on Redis channel {$this->channel} started before the reader subscribed.");
            }
            $this->streamId = $envelope->streamId;
        }
        if ($envelope->streamId !== $this->streamId) {
            throw new ChannelReadException("Segment {$this->streamId} on Redis channel {$this->channel} ended without a terminal event: another segment started.");
        }
        if (in_array($envelope->type, self::TERMINAL_TYPES, true)) {
            return false;
        }

        $onEvent(new ProtocolEvent($envelope->type, (array) $envelope->data));

        return true;
    }

    protected function timedOut(): bool
    {
        return microtime(true) - $this->heardAt >= $this->timeout;
    }
}
