<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Psr\Http\Message\StreamInterface as PsrStreamInterface;

use function strpos;
use function substr;

/**
 * Adapter for Guzzle's PSR-7 StreamInterface.
 *
 * Wraps Guzzle's stream to provide a framework-agnostic interface
 * for reading streaming HTTP responses.
 */
class GuzzleStream implements StreamInterface
{
    private string $buffer = '';

    public function __construct(
        private readonly PsrStreamInterface $stream
    ) {
    }

    public function eof(): bool
    {
        return $this->buffer === '' && $this->stream->eof();
    }

    public function read(int $length): string
    {
        if ($this->buffer !== '') {
            $result = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            return $result;
        }

        return $this->stream->read($length);
    }

    public function readLine(): string
    {
        while (true) {
            $pos = strpos($this->buffer, "\n");

            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos + 1);
                $this->buffer = substr($this->buffer, $pos + 1);
                return $line;
            }

            if ($this->stream->eof()) {
                $line = $this->buffer;
                $this->buffer = '';
                return $line;
            }

            $chunk = $this->stream->read(10);
            if ($chunk === '') {
                $line = $this->buffer;
                $this->buffer = '';
                return $line;
            }
            $this->buffer .= $chunk;
        }
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->stream->close();
    }
}
