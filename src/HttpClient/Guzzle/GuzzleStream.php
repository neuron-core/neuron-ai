<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient\Guzzle;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use Psr\Http\Message\StreamInterface as PsrStreamInterface;

use function strlen;
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

    protected int $received = 0;

    /**
     * @param int|null $contentLength The body size the response declares, if any
     */
    public function __construct(
        private readonly PsrStreamInterface $stream,
        protected readonly HttpRequest $request,
        protected readonly ?int $contentLength = null,
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

        return $this->pull($length);
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

    /**
     * Guzzle streams through PHP's stream reader, which ends quietly when the connection
     * drops: a body shorter than its Content-Length is the only cut it lets us see.
     *
     * @throws HttpException
     */
    protected function pull(int $length): string
    {
        $chunk = $this->stream->read($length);
        $this->received += strlen($chunk);

        if ($this->contentLength !== null && $this->received < $this->contentLength && $this->stream->eof()) {
            throw HttpException::networkError(
                $this->request,
                "Response body ended after {$this->received} of the {$this->contentLength} bytes declared by Content-Length",
            );
        }

        return $chunk;
    }
}
