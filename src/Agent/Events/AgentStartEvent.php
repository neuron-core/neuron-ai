<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

use function max;

class AgentStartEvent implements Event
{
    /**
     * @var Message[]
     */
    protected array $messages = [];

    /**
     * Inference intent — how the run wants its inference performed. Recorded on
     * whatever start event the workflow resolves, and honored where the
     * inference event is born: StartNode for a plain Agent, InstructionsNode
     * for RAG.
     */
    public bool $stream = false;
    public ?string $outputClass = null;
    public int $maxTries = 1;

    public function setMessages(Message ...$messages): void
    {
        $this->messages = $messages;
    }

    /**
     * @return Message[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setStream(bool $stream = true): static
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * Record structured-output intent. Recording never changes the event's
     * class — the routed class is derived where the inference event is born
     * (AIInferenceEvent::routed()).
     */
    public function setStructuredOutput(string $outputClass, int $maxTries = 1): static
    {
        $this->outputClass = $outputClass;
        $this->maxTries = max(1, $maxTries);

        return $this;
    }
}
