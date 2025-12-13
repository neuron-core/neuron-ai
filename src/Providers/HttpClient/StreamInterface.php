<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HttpClient;

/**
 * Framework-agnostic stream interface for reading HTTP response bodies.
 *
 * This interface abstracts streaming operations to allow providers to work with
 * any HTTP client implementation (Guzzle, Amp, ReactPHP, etc.) when reading
 * Server-Sent Events (SSE) or other streaming responses.
 */
interface StreamInterface
{
    /**
     * Check if the stream has reached end-of-file.
     */
    public function eof(): bool;

    /**
     * Read data from the stream.
     *
     * @param int $length Maximum number of bytes to read
     * @return string Data read from the stream (may be less than $length)
     */
    public function read(int $length): string;

    /**
     * Read a single line from the stream up to a newline character.
     *
     * @return string The line read (including newline character if present)
     */
    public function readLine(): string;

    /**
     * Close the stream and free any associated resources.
     */
    public function close(): void;
}
