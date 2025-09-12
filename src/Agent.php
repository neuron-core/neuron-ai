<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Observability\Observable;

class Agent implements AgentInterface
{
    use StaticConstructor;
    use ResolveProvider;
    use HandleTools;
    use ResolveChatHistory;
    use HandleChat;
    use HandleStream;
    use HandleStructured;
    use Observable;

    /**
     * The system instructions.
     */
    protected string $instructions;

    public function withInstructions(string $instructions): AgentInterface
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function instructions(): string
    {
        return 'Your are a helpful and friendly AI agent built with Neuron PHP framework.';
    }

    public function resolveInstructions(): string
    {
        return $this->instructions ?? $this->instructions();
    }

    protected function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        // Escape special regex characters in the tags
        $escapedOpenTag = \preg_quote($openTag, '/');
        $escapedCloseTag = \preg_quote($closeTag, '/');

        // Create the regex pattern to match content between tags
        $pattern = '/' . $escapedOpenTag . '.*?' . $escapedCloseTag . '/s';

        // Remove all occurrences of the delimited content
        return \preg_replace($pattern, '', $text);
    }
}
