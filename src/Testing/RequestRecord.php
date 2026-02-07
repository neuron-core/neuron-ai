<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

class RequestRecord
{
    /**
     * @param string $method The method called: 'chat', 'stream', or 'structured'
     * @param Message[] $messages The messages passed to the provider
     * @param string|null $systemPrompt The system prompt set at call time
     * @param array<ToolInterface> $tools The tools configured at call time
     * @param string|null $structuredClass The output class (structured only)
     * @param array<string, mixed> $structuredSchema The response schema (structured only)
     */
    public function __construct(
        public readonly string $method,
        public readonly array $messages,
        public readonly ?string $systemPrompt,
        public readonly array $tools,
        public readonly ?string $structuredClass = null,
        public readonly array $structuredSchema = [],
    ) {
    }
}
