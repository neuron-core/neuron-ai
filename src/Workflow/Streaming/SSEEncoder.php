<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming;

use Generator;
use JsonException;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Server-Sent Events framing for the pull path. Applied by the HTTP edge,
 * never by the Workflow, so channels and every other consumer keep receiving
 * the protocol events themselves.
 */
final class SSEEncoder
{
    /**
     * @template TReturn
     * @param Generator<int, ProtocolEvent, mixed, TReturn> $events
     * @return Generator<int, string, mixed, TReturn>
     */
    public static function encode(Generator $events): Generator
    {
        while ($events->valid()) {
            try {
                $frame = self::frame($events->current());
            } catch (JsonException $e) {
                // The wire cannot carry this event. Raised inside the stream,
                // the failure lets the adapter close its protocol and the run
                // settle as failed; the generator then stands on the first
                // failure frame, or has already rethrown.
                $events->throw($e);
                continue;
            }

            yield $frame;
            $events->next();
        }

        return $events->getReturn();
    }

    /**
     * Invalid UTF-8 (a tool reading a legacy file, a byte split by a provider)
     * becomes U+FFFD instead of a failed stream.
     */
    public static function frame(ProtocolEvent $event): string
    {
        return 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    }
}
