<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming;

use Generator;

use function json_encode;

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
        foreach ($events as $event) {
            yield self::frame($event);
        }

        return $events->getReturn();
    }

    public static function frame(ProtocolEvent $event): string
    {
        return 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n";
    }
}
