<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ToolInterface;

use function get_object_vars;
use function is_string;

/**
 * Carries the inference configuration; middleware can adjust instructions,
 * tools, and intent before the provider call.
 */
class AIInferenceEvent extends AgentStartEvent
{
    public SystemMessage $instructions;

    /**
     * @param SystemMessage|string $instructions System instructions for the agent
     * @param ToolInterface[] $tools Available tools for the agent
     */
    public function __construct(
        SystemMessage|string $instructions,
        public array $tools,
    ) {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
    }

    /**
     * An inference event routes by its exact class: recorded structured
     * intent derives a StructuredInferenceEvent carrying the same
     * instructions, tools, messages, and intent fields. Idempotent — an
     * event already routed (or without structured intent) returns itself.
     */
    public function routed(): AIInferenceEvent
    {
        if ($this->outputClass === null || $this instanceof StructuredInferenceEvent) {
            return $this;
        }

        $event = new StructuredInferenceEvent($this->instructions, $this->tools);
        $event->setMessages(...$this->messages);
        $event->stream = $this->stream;
        $event->outputClass = $this->outputClass;
        $event->maxTries = $this->maxTries;

        return $event;
    }

    /**
     * Tools are execution capability (connections, clients, closures) and
     * capability is never persisted: a stored step carries this event without
     * its tool list; Workflow::restoreEvent() re-seeds the live registry on recall.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        $data['tools'] = [];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
