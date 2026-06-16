<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

class InferenceStart
{
    /**
     * @param Message[] $messages Full chat history being sent to the provider
     * @param ToolInterface[] $tools
     */
    public function __construct(
        public Message $message,
        public string $instructions = '',
        public array $tools = [],
        public array $messages = [],
    ) {
    }
}
