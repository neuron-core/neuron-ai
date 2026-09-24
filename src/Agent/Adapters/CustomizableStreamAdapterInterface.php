<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;

interface CustomizableStreamAdapterInterface extends StreamAdapterInterface
{
    /**
     * @template TEvent of object
     * @param class-string<TEvent> $eventClass
     * @param callable(TEvent): (StreamEventInterface|null) $mapper
     */
    public function mapEvent(string $eventClass, callable $mapper): static;
}
