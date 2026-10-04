<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mercure\Exception\RuntimeException as PublishException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

use function http_build_query;
use function implode;
use function max;
use function microtime;
use function strlen;
use function usleep;

use const PHP_INT_MAX;

/**
 * Publishes a segment as Mercure updates, each holding a JSON array of envelopes.
 * A hub that limits its publish rate gets one update per slot, carrying what the
 * segment produced meanwhile: a partial update leaves with the next event or at
 * the end of the segment.
 */
class MercureChannel extends AbstractChannel
{
    /** Seconds the channel may hold the run waiting for the hub before giving the segment up. */
    protected const MAX_WAIT = 10.0;

    protected float $interval;

    protected float $nextRequest = 0.0;

    /** Seconds of waiting the run has not worked off yet. */
    protected float $frozen = 0.0;

    protected float $resumedAt = 0.0;

    /**
     * @param int $maxRequestBytes The hub's publish request limit, form encoding included:
     *                             max_request_body_size, or the plan's message size on Mercure Cloud.
     * @param ?float $maxRequestsPerSecond The share of the hub's publish rate this run may use.
     *                                     Null publishes every event as it is produced.
     */
    public function __construct(
        protected HubInterface $hub,
        protected string $topic,
        protected int $maxRequestBytes = 1_048_576,
        ?float $maxRequestsPerSecond = null,
        protected bool $private = true,
    ) {
        // The end of a segment can need three requests in a row: a full update, the pending one and the terminal event.
        if ($maxRequestsPerSecond !== null && $maxRequestsPerSecond * self::MAX_WAIT < 3) {
            throw new InvalidArgumentException(
                'Mercure request rate must allow three requests every ' . self::MAX_WAIT . ' seconds: the channel never waits longer for the hub.'
            );
        }

        $this->interval = $maxRequestsPerSecond === null ? 0.0 : 1 / $maxRequestsPerSecond;
    }

    /** An open slot sends what is pending; a closed one keeps packing until the request is full. */
    protected function batchSize(): int
    {
        return $this->now() >= $this->nextRequest ? 1 : PHP_INT_MAX;
    }

    protected function budget(): int
    {
        return $this->maxRequestBytes;
    }

    /** @param list<string> $events */
    protected function batch(array $events): string
    {
        return '[' . implode(',', $events) . ']';
    }

    /**
     * The request as the hub receives it: form encoding triples JSON punctuation.
     *
     * @param list<string> $events
     */
    protected function batchBytes(array $events): int
    {
        return strlen(http_build_query(['topic' => $this->topic, 'data' => $this->batch($events)], '', '&'))
            + ($this->private ? strlen('&private=on') : 0);
    }

    protected function deliver(string $batch): void
    {
        while (true) {
            $this->waitForSlot();

            try {
                $this->hub->publish(new Update($this->topic, $batch, $this->private));
                $this->nextRequest = $this->now() + $this->interval;

                return;
            } catch (PublishException $e) {
                $this->nextRequest = $this->now() + $this->retryAfter($e);
            }
        }
    }

    /**
     * Seconds before the hub accepts another request, when it refused this one for its rate.
     *
     * @throws PublishException Any other failure.
     */
    protected function retryAfter(PublishException $e): float
    {
        $refusal = $e->getPrevious();
        if (!$refusal instanceof HttpExceptionInterface || $refusal->getResponse()->getStatusCode() !== 429) {
            throw $e;
        }

        $seconds = (float) ($refusal->getResponse()->getHeaders(false)['retry-after'][0] ?? 0);

        return $seconds > 0 ? $seconds : 1.0;
    }

    /**
     * Waiting freezes the run, so it counts against MAX_WAIT until the run works it off:
     * a stream slowed by its hub goes on, one frozen by it is given up.
     */
    protected function waitForSlot(): void
    {
        $wait = $this->nextRequest - $this->now();
        if ($wait <= 0) {
            return;
        }

        $this->frozen = max(0.0, $this->frozen - ($this->now() - $this->resumedAt)) + $wait;
        if ($this->frozen > self::MAX_WAIT) {
            $this->frozen = 0.0;
            throw new RuntimeException(
                'Mercure streaming stopped: delivering within the hub limits would hold the run for more than ' . self::MAX_WAIT . ' seconds.'
            );
        }

        $this->sleep($wait);
        $this->resumedAt = $this->now();
    }

    protected function now(): float
    {
        return microtime(true);
    }

    protected function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
