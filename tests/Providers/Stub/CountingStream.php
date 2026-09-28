<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Stub;

use NeuronAI\HttpClient\StreamInterface;

use function strlen;
use function strpos;
use function substr;

/**
 * A response body served from memory that counts how many times it is read.
 */
class CountingStream implements StreamInterface
{
    public int $readCalls = 0;

    public int $readLineCalls = 0;

    public function __construct(protected string $body)
    {
    }

    public function eof(): bool
    {
        return $this->body === '';
    }

    public function read(int $length): string
    {
        $this->readCalls++;

        return $this->take($length);
    }

    public function readLine(): string
    {
        $this->readLineCalls++;
        $newline = strpos($this->body, "\n");

        return $this->take($newline === false ? strlen($this->body) : $newline + 1);
    }

    public function close(): void
    {
    }

    protected function take(int $length): string
    {
        $chunk = substr($this->body, 0, $length);
        $this->body = substr($this->body, strlen($chunk));

        return $chunk;
    }
}
