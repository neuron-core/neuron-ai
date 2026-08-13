<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use CurlHandle;
use CurlMultiHandle;
use NeuronAI\Exceptions\HttpException;

use function curl_multi_close;
use function curl_multi_exec;
use function curl_multi_info_read;
use function curl_multi_remove_handle;
use function curl_multi_select;
use function curl_strerror;
use function strlen;
use function strpos;
use function substr;
use function usleep;

use const CURLE_OK;
use const CURLM_CALL_MULTI_PERFORM;

/**
 * Streaming HTTP response backed by a curl multi handle.
 *
 * Data is pumped from the transfer on demand: each read drives the
 * curl event loop until new bytes arrive, so SSE chunks are delivered
 * incrementally as the server sends them.
 */
class CurlStream implements StreamInterface
{
    /**
     * Consumed-bytes threshold past which the buffer is compacted even
     * when not fully drained, bounding memory on partially-read buffers.
     */
    protected const COMPACT_THRESHOLD = 65536;

    protected string $buffer = '';
    protected int $offset = 0;
    protected bool $complete = false;

    protected CurlHeaderCollector $headers;

    public function __construct(
        protected CurlMultiHandle $multiHandle,
        protected CurlHandle $handle,
        protected HttpRequest $request,
    ) {
        $this->headers = new CurlHeaderCollector();
    }

    /**
     * Receive a body chunk from curl's write callback.
     */
    public function write(string $data): int
    {
        $this->buffer .= $data;
        return strlen($data);
    }

    /**
     * Receive a header line from curl's header callback.
     */
    public function writeHeader(string $line): int
    {
        return $this->headers->ingestLine($line);
    }

    public function getStatusCode(): int
    {
        return $this->headers->getStatusCode();
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers->getHeaders();
    }

    /**
     * Drive the transfer until the final response headers are available.
     *
     * @throws HttpException on network failure
     */
    public function awaitHeaders(): void
    {
        while (!$this->headers->isComplete() && !$this->complete) {
            $this->pumpOnce();
        }
    }

    /**
     * Drive the transfer to completion, buffering the remaining body.
     *
     * @throws HttpException on network failure
     */
    public function drain(): string
    {
        while (!$this->complete) {
            $this->pumpOnce();
        }

        $body = substr($this->buffer, $this->offset);
        $this->buffer = '';
        $this->offset = 0;

        return $body;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && $this->complete;
    }

    public function read(int $length): string
    {
        $this->fill();

        $result = substr($this->buffer, $this->offset, $length);
        $this->advance($this->offset + strlen($result));

        return $result;
    }

    public function readLine(): string
    {
        $searchFrom = $this->offset;

        while (true) {
            $pos = strpos($this->buffer, "\n", $searchFrom);

            if ($pos !== false) {
                $line = substr($this->buffer, $this->offset, $pos - $this->offset + 1);
                $this->advance($pos + 1);
                return $line;
            }

            if ($this->complete) {
                $line = substr($this->buffer, $this->offset);
                $this->buffer = '';
                $this->offset = 0;
                return $line;
            }

            // Each byte is scanned once: resume the newline search where it stopped.
            $searchFrom = strlen($this->buffer);
            $this->pumpOnce();
        }
    }

    public function close(): void
    {
        $this->complete = true;
        $this->buffer = '';
        $this->offset = 0;

        curl_multi_remove_handle($this->multiHandle, $this->handle);
        curl_multi_close($this->multiHandle);
    }

    /**
     * Move the read cursor, compacting the buffer once it is fully
     * consumed (the common SSE case) or past the compaction threshold.
     */
    protected function advance(int $offset): void
    {
        if ($offset >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
            return;
        }

        if ($offset > self::COMPACT_THRESHOLD) {
            $this->buffer = substr($this->buffer, $offset);
            $this->offset = 0;
            return;
        }

        $this->offset = $offset;
    }

    /**
     * Block until at least one body byte is buffered or the transfer ends.
     *
     * @throws HttpException on network failure
     */
    protected function fill(): void
    {
        while ($this->buffer === '' && !$this->complete) {
            $this->pumpOnce();
        }
    }

    /**
     * Run one iteration of the curl event loop.
     *
     * @throws HttpException on network failure
     */
    protected function pumpOnce(): void
    {
        do {
            $status = curl_multi_exec($this->multiHandle, $stillRunning);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($stillRunning > 0) {
            // Wait for socket activity; fall back to a short sleep when
            // curl has no file descriptors to watch yet.
            if (curl_multi_select($this->multiHandle, 1.0) === -1) {
                usleep(1000);
            }
            return;
        }

        $this->complete = true;

        while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
            if ($info['result'] !== CURLE_OK) {
                throw HttpException::networkError($this->request, (string) curl_strerror($info['result']));
            }
        }
    }
}
