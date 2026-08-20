<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\Events\StreamEventInterface;
use NeuronAI\Exceptions\StreamAdapterException;

trait MapsStreamEvents
{
    /**
     * @var array<class-string<object>, callable(object): (StreamEventInterface|null)>
     */
    protected array $eventMappings = [];

    /**
     * @template TEvent of object
     * @param class-string<TEvent> $eventClass
     * @param callable(TEvent): (StreamEventInterface|null) $mapper
     */
    public function mapEvent(string $eventClass, callable $mapper): static
    {
        $this->eventMappings[$eventClass] = $mapper;

        return $this;
    }

    /**
     * The boolean distinguishes an unmapped object from an explicitly
     * suppressed mapping that returned null.
     *
     * @return array{bool, StreamEventInterface|null}
     * @throws StreamAdapterException
     */
    protected function resolveStreamEvent(object $event): array
    {
        if ($event instanceof StreamEventInterface) {
            return [true, $event];
        }

        if (! isset($this->eventMappings[$event::class])) {
            return [false, null];
        }

        $mapped = ($this->eventMappings[$event::class])($event);

        if ($mapped !== null && ! $mapped instanceof StreamEventInterface) {
            throw new StreamAdapterException(
                'The stream event mapper for ' . $event::class . ' must return '
                . StreamEventInterface::class
                . ' or null.'
            );
        }

        return [true, $mapped];
    }
}
