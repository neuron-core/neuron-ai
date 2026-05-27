<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;

use function strlen;
use function substr;
use function strpos;

/**
 * Adapter for Amp's ReadableStream.
 *
 * Wraps Amp's async stream to provide a framework-agnostic interface
 * for reading streaming HTTP responses in async contexts.
 */
class AmpStream implements StreamInterface
{
    private bool $eof = false;
    private string $buffer = '';

    public function __construct(
        private readonly ReadableStream $stream
    ) {
    }

    public function eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    public function read(int $length): string
    {
        try {
            // If we have buffered data, return from buffer first
            if ($this->buffer !== '') {
                $result = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, $length);
                return $result;
            }

            // Read from stream
            $chunk = $this->stream->read();

            if ($chunk === null) {
                $this->eof = true;
                return '';
            }

            // If chunk is larger than requested, buffer the rest
            if (strlen($chunk) > $length) {
                $result = substr($chunk, 0, $length);
                $this->buffer = substr($chunk, $length);
                return $result;
            }

            return $chunk;
        } catch (StreamException) {
            $this->eof = true;
            return '';
        }
    }

    public function readLine(): string
    {
        $line = '';

        while (!$this->eof()) {
            // Try to find newline in existing buffer first
            if ($this->buffer !== '') {
                $pos = strpos($this->buffer, "\n");

                if ($pos !== false) {
                    $line .= substr($this->buffer, 0, $pos + 1);
                    $this->buffer = substr($this->buffer, $pos + 1);
                    return $line;
                }

                $line .= $this->buffer;
                $this->buffer = '';
            }

            $chunk = $this->stream->read();

            if ($chunk === null) {
                $this->eof = true;
                break;
            }

            $this->buffer = $chunk;
        }

        return $line;
    }

    public function close(): void
    {
        $this->eof = true;
        $this->buffer = '';
    }
}
