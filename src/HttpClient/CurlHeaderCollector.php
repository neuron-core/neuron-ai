<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function explode;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Parses the header lines curl feeds to CURLOPT_HEADERFUNCTION.
 *
 * Redirect responses produce their own header block; each new status line
 * resets the collected headers so only the final response survives.
 */
class CurlHeaderCollector
{
    protected int $statusCode = 0;

    /**
     * @var array<string, string[]>
     */
    protected array $headers = [];

    protected bool $complete = false;

    /**
     * Ingest one header line and return its length, as curl's callback contract requires.
     */
    public function ingestLine(string $line): int
    {
        $length = strlen($line);
        $trimmed = trim($line);

        if (str_starts_with($trimmed, 'HTTP/')) {
            $this->headers = [];
            $this->complete = false;
            $parts = explode(' ', $trimmed, 3);
            $this->statusCode = (int) ($parts[1] ?? 0);
            return $length;
        }

        if ($trimmed === '') {
            // End of a header block: final unless curl is about to follow a redirect.
            $this->complete = $this->statusCode < 300 || $this->statusCode >= 400;
            return $length;
        }

        $separator = strpos($trimmed, ':');
        if ($separator !== false) {
            $name = trim(substr($trimmed, 0, $separator));
            $this->headers[$name][] = trim(substr($trimmed, $separator + 1));
        }

        return $length;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
