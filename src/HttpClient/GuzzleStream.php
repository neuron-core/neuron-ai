<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Psr\Http\Message\StreamInterface as PsrStreamInterface;

use function max;
use function strlen;
use function strpos;
use function substr;

class GuzzleStream implements StreamInterface
{
    private string $buffer = '';

    public function __construct(
        private readonly PsrStreamInterface $stream
    ) {
    }

    public function eof(): bool
    {
        return $this->stream->eof() && $this->buffer === '';
    }

    public function read(int $length): string
    {
        if ($this->buffer !== '') {
            $result = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            return $result;
        }

        if ($this->stream->eof()) {
            return '';
        }

        $chunk = $this->stream->read(max($length, 8192));

        if (strlen($chunk) <= $length) {
            return $chunk;
        }

        $result = substr($chunk, 0, $length);
        $this->buffer = substr($chunk, $length);

        return $result;
    }

    public function readLine(): string
    {
        $line = '';

        while (!$this->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $pos = strpos($chunk, "\n");

            if ($pos !== false) {
                $line .= substr($chunk, 0, $pos + 1);
                $this->buffer = substr($chunk, $pos + 1) . $this->buffer;
                break;
            }

            $line .= $chunk;
        }

        return $line;
    }

    public function close(): void
    {
        $this->stream->close();
        $this->buffer = '';
    }
}
