<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ToolInterface;

use function get_object_vars;
use function is_string;
use function serialize;
use function unserialize;

/**
 * Working input for inference: pending messages, enriched instructions, effective
 * tools, and run options. AgentState owns the request throughout the run.
 */
class InferenceRequest
{
    public SystemMessage $instructions;

    /**
     * @param ToolInterface[] $tools
     * @param Message[] $messages Messages awaiting inference and history commit.
     */
    public function __construct(
        SystemMessage|string $instructions,
        public array $tools = [],
        public array $messages = [],
        public AgentRunOptions $options = new AgentRunOptions(),
    ) {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
    }

    public function __clone(): void
    {
        // Copy mutable data deeply without serializing live tool dependencies.
        [$this->instructions, $this->messages, $this->options] = unserialize(serialize([
            $this->instructions,
            $this->messages,
            $this->options,
        ]));
    }

    /**
     * Executable tools can hold connections and closures. Resume restores the
     * live registry; only request data belongs in durable workflow records.
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
