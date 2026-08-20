<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\Events\StreamEventInterface;

interface CustomizableStreamAdapterInterface extends StreamAdapterInterface
{
    /**
     * @template TEvent of object
     * @param class-string<TEvent> $eventClass
     * @param callable(TEvent): (StreamEventInterface|null) $mapper
     */
    public function mapEvent(string $eventClass, callable $mapper): static;
}
