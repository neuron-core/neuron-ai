<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient\Curl;

use Closure;

use function explode;
use function str_starts_with;
use function strcasecmp;
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

    protected ?string $refusedRedirect = null;

    /**
     * @param Closure(string): bool $allowsRedirectTo Whether curl may follow a redirect to this location
     */
    public function __construct(protected Closure $allowsRedirectTo)
    {
    }

    /**
     * Ingest one header line and return its length, as curl's callback contract requires.
     * A refused redirect returns 0 instead: curl aborts the transfer before following it.
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
            // End of a header block: final unless it is informational (1xx) or curl is about to follow a redirect.
            $this->complete = $this->statusCode >= 200 && ($this->statusCode < 300 || $this->statusCode >= 400);
            return $length;
        }

        $separator = strpos($trimmed, ':');
        if ($separator !== false) {
            $name = trim(substr($trimmed, 0, $separator));
            $value = trim(substr($trimmed, $separator + 1));

            if ($this->statusCode >= 300 && $this->statusCode < 400 && strcasecmp($name, 'Location') === 0 && !($this->allowsRedirectTo)($value)) {
                $this->refusedRedirect = $value;
                return 0;
            }

            $this->headers[$name][] = $value;
        }

        return $length;
    }

    /**
     * Why the transfer stopped, when curl was about to follow a refused redirect.
     */
    public function getRefusal(): ?string
    {
        return $this->refusedRedirect === null ? null : "refused a redirect to another origin: {$this->refusedRedirect}";
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
