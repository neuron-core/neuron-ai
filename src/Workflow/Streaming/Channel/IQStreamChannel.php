<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use IQStream\Frame;
use IQStream\IQClient;
use IQStream\Outcome;
use IQStream\Publisher;
use IQStream\StreamId;
use RuntimeException;
use Throwable;

/**
 * Publishes a segment to an IQ streaming server through its PHP client, neuron-core/iq-stream-php: one
 * publisher holds one request open from the segment's first event to its end, every envelope is one
 * frame of it, written as it is produced, and the terminal envelope closes the stream.
 *
 * The client signs each request with the app's secret, continues the stream over a new request after
 * the server let go of a silent one, as while a tool runs, and resends what a lost connection did not
 * deliver.
 */
class IQStreamChannel extends AbstractChannel
{
    protected StreamId $stream;

    protected ?Publisher $publisher = null;

    /** How the stream ends once the envelope being delivered is sent: set by a terminal event. */
    protected ?Outcome $closing = null;

    /**
     * @param IQClient $client The application's client of the IQ server, which holds the app's key and secret.
     * @param StreamId|string $stream The stream of the ticket the browser subscribes with. A closed stream takes
     *                                no more frames: every segment, a resumed one included, publishes to a new one.
     */
    public function __construct(
        protected IQClient $client,
        StreamId|string $stream,
    ) {
        $this->stream = $stream instanceof StreamId ? $stream : new StreamId($stream);
    }

    /** The longest line the server takes, as the client measures a frame. */
    protected function budget(): int
    {
        return $this->publisher()->maxFrameBytes();
    }

    /**
     * @param list<string> $events
     */
    protected function batchBytes(array $events): int
    {
        return (new Frame($this->batch($events)))->size();
    }

    /**
     * The envelope is the frame's data, without an event name, so every frame reaches a subscriber's
     * `message` listener. A terminal event closes the stream after its own frame: `end` for a completed
     * or interrupted segment, which ended as it should, and `fail` for a failed one.
     */
    protected function encode(string $type, string $envelope): string
    {
        $this->closing = match ($type) {
            'stream.completed', 'stream.interrupted' => Outcome::End,
            'stream.failed' => Outcome::Fail,
            default => $this->closing,
        };

        return $envelope;
    }

    /**
     * @throws Throwable
     */
    protected function deliver(string $batch): void
    {
        $publisher = $this->publisher();
        if (!$publisher->send($batch)) {
            throw $this->stopped($publisher);
        }

        if ($this->closing instanceof Outcome) {
            $result = $publisher->close($this->closing);
            if (!$result->closed || $result->outcome !== $this->closing) {
                throw $this->stopped($publisher);
            }
        }
    }

    /** Made on the segment's first event: its request opens then. */
    protected function publisher(): Publisher
    {
        return $this->publisher ??= $this->client->publisher($this->stream);
    }

    /** Why the publisher stopped: the server's refusal, or what kept the server out of reach. */
    protected function stopped(Publisher $publisher): Throwable
    {
        return $publisher->error() ?? new RuntimeException("IQ stream {$this->stream} stopped.");
    }
}
