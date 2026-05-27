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
        $line = '';

        while (true) {
            $chunk = $this->read(512);

            if ($chunk === '') {
                return $line;
            }

            $line .= $chunk;

            $pos = strpos($line, "\n");

            if ($pos !== false) {
                $this->buffer = substr($line, $pos + 1) . $this->buffer;
                return substr($line, 0, $pos + 1);
            }
        }
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->stream->close();
    }
}
