<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Psr\Http\Message\StreamInterface as PsrStreamInterface;

/**
 * Adapter for Guzzle's PSR-7 StreamInterface.
 *
 * Wraps Guzzle's stream to provide a framework-agnostic interface
 * for reading streaming HTTP responses.
 */
final class GuzzleStream implements StreamInterface
{
    public function __construct(
        private readonly PsrStreamInterface $stream
    ) {
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function readLine(): string
    {
        $buffer = '';

        while (!$this->stream->eof()) {
            if ('' === ($byte = $this->stream->read(1))) {
                return $buffer;
            }
            $buffer .= $byte;
            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
