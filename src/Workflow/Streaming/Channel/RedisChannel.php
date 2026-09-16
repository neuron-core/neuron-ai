<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use JsonException;
use Redis;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final class RedisChannel extends AbstractChannel
{
    public function __construct(
        protected Redis $client,
        protected string $channel,
    ) {
    }

    /**
     * Invalid UTF-8 becomes U+FFFD instead of a lost message, as on the SSE path.
     *
     * @throws JsonException
     */
    protected function deliver(array $events): void
    {
        foreach ($events as $event) {
            $this->client->publish($this->channel, json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
