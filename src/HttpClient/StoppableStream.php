<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Closure;

/**
 * A stream that ends as soon as the application asks it to stop. Stopping
 * closes the connection, so the vendor stops generating (and billing).
 */
class StoppableStream implements StreamInterface
{
    protected bool $stopped = false;

    /**
     * @param Closure(): bool $shouldStop
     */
    public function __construct(
        protected StreamInterface $stream,
        protected Closure $shouldStop,
    ) {
    }

    public function eof(): bool
    {
        if (!$this->stopped && ($this->shouldStop)()) {
            $this->stopped = true;
            $this->stream->close();
        }

        return $this->stopped || $this->stream->eof();
    }

    /**
     * Whether the stream ended because the application stopped it.
     */
    public function stopped(): bool
    {
        return $this->stopped;
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function readLine(): string
    {
        return $this->stream->readLine();
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
