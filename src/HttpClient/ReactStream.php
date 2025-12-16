<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Psr\Http\Message\StreamInterface as PsrStreamInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStreamInterface;

use function strlen;
use function substr;

/**
 * Adapter for ReactPHP's ReadableStreamInterface.
 *
 * Wraps ReactPHP's async stream to provide a framework-agnostic interface
 * for reading streaming HTTP responses in async contexts.
 */
class ReactStream implements StreamInterface
{
    private bool $eof = false;
    private string $buffer = '';
    private bool $dataReceived = false;

    public function __construct(
        private readonly ReadableStreamInterface|PsrStreamInterface $stream,
        private readonly ?LoopInterface $loop = null,
    ) {
        $this->setupStreamListeners();
    }

    public function eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    public function read(int $length): string
    {
        // If we have buffered data, return from buffer first
        if ($this->buffer !== '') {
            $result = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            return $result;
        }

        // If already at EOF, return empty string
        if ($this->eof) {
            return '';
        }

        // Wait for data to arrive from the stream
        $this->waitForData();

        // After waiting, check buffer again
        if ($this->buffer !== '') {
            $result = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            return $result;
        }

        return '';
    }

    public function readLine(): string
    {
        $line = '';

        while (!$this->eof()) {
            $byte = $this->read(1);

            if ($byte === '') {
                return $line;
            }

            $line .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $line;
    }

    public function close(): void
    {
        $this->eof = true;
        $this->buffer = '';

        if ($this->stream instanceof ReadableStreamInterface && $this->stream->isReadable()) {
            $this->stream->close();
        } elseif ($this->stream instanceof PsrStreamInterface) {
            $this->stream->close();
        }
    }

    private function setupStreamListeners(): void
    {
        if (!$this->stream instanceof ReadableStreamInterface) {
            // If it's a PSR-7 stream, we don't need event listeners
            return;
        }

        $this->stream->on('data', function ($data): void {
            $this->buffer .= $data;
            $this->dataReceived = true;
        });

        $this->stream->on('end', function (): void {
            $this->eof = true;
        });

        $this->stream->on('error', function (): void {
            $this->eof = true;
        });

        $this->stream->on('close', function (): void {
            $this->eof = true;
        });
    }

    private function waitForData(): void
    {
        if (!$this->stream instanceof ReadableStreamInterface) {
            // If it's a PSR-7 stream, read directly
            if ($this->stream instanceof PsrStreamInterface && !$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
                if ($chunk !== '') {
                    $this->buffer .= $chunk;
                } else {
                    $this->eof = true;
                }
            }
            return;
        }

        $loop = $this->loop ?? Loop::get();

        // Reset the data received flag
        $this->dataReceived = false;

        // Run the event loop until we receive data or reach EOF
        $loop->futureTick(function () use ($loop): void {
            if (!$this->dataReceived && !$this->eof) {
                $loop->stop();
            }
        });

        // Add a timer to prevent infinite waiting
        $timer = $loop->addTimer(0.1, function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();

        $loop->cancelTimer($timer);
    }
}
