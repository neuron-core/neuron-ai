<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\RouterNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;

/**
 * Agent implementation built on top of the Workflow system.
 *
 * This implementation leverages workflow features like:
 * - Interruption for human-in-the-loop patterns
 * - Persistence for resuming workflows
 * - Checkpoints for state management
 * - Event-driven architecture for complex execution flows
 */
class Agent implements AgentInterface
{
    use StaticConstructor;
    use Observable;
    use ResolveProvider;
    use HandleTools;
    use ResolveChatHistory;

    protected AIProviderInterface $provider;

    protected string $instructions = '';

    protected AgentState $state;

    protected Workflow $workflow;

    public function __construct(?AgentState $state = null)
    {
        $this->state = $state ?? new AgentState();
    }

    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function instructions(): string
    {
        return 'You are a helpful and friendly AI agent built with Neuron PHP framework.';
    }

    public function resolveInstructions(): string
    {
        return $this->instructions !== '' ? $this->instructions : $this->instructions();
    }

    protected function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        $escapedOpenTag = \preg_quote($openTag, '/');
        $escapedCloseTag = \preg_quote($closeTag, '/');
        $pattern = '/' . $escapedOpenTag . '.*?' . $escapedCloseTag . '/s';
        return \preg_replace($pattern, '', $text);
    }

    /**
     * Build the workflow with nodes.
     */
    protected function buildWorkflow(Node $aiProviderNode): Workflow
    {
        $workflow = Workflow::make($this->state)
            ->addNodes([
                $aiProviderNode,
                new RouterNode(),
                new ToolNode($this->toolMaxTries),
            ]);

        // Share observers with workflow
        foreach ($this->observers as $event => $observers) {
            foreach ($observers as $observer) {
                $workflow->observe($observer, $event);
            }
        }

        return $workflow;
    }

    /**
     * Execute the chat.
     *
     * @param Message|Message[] $messages
     * @throws \Throwable
     */
    public function chat(Message|array $messages): Message
    {
        $this->notify('chat-start');

        $messages = \is_array($messages) ? $messages : [$messages];

        // Add messages to chat history before building workflow
        $chatHistory = $this->state->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        $workflow = $this->buildWorkflow(
            new ChatNode(
                $this->resolveProvider(),
                $this->resolveInstructions(),
                $this->bootstrapTools()
            )
        );
        $handler = $workflow->start();

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('chat-stop');

        return $finalState->getChatHistory()->getLastMessage();
    }

    /**
     * Execute the chat with streaming.
     *
     * @param Message|Message[] $messages
     * @throws \Throwable
     */
    public function stream(Message|array $messages): \Generator
    {
        $this->notify('stream-start');

        $messages = \is_array($messages) ? $messages : [$messages];

        // Add messages to chat history before building workflow
        $chatHistory = $this->state->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        $workflow = $this->buildWorkflow(
            new StreamingNode(
                $this->resolveProvider(),
                $this->resolveInstructions(),
                $this->bootstrapTools()
            )
        );
        $handler = $workflow->start();

        // Stream events and yield only StreamChunk objects
        foreach ($handler->streamEvents() as $event) {
            yield $event;
        }

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('stream-stop');

        return $finalState->getChatHistory()->getLastMessage();
    }

    /**
     * Execute structured output extraction.
     *
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws \Throwable
     */
    public function structured(Message|array $messages, ?string $class = null, int $maxRetries = 1): mixed
    {
        $this->notify('structured-start');

        $messages = \is_array($messages) ? $messages : [$messages];

        // Add messages to chat history before building workflow
        $chatHistory = $this->state->getChatHistory();
        foreach ($messages as $message) {
            $chatHistory->addMessage($message);
        }

        // Get the output class
        $class ??= $this->getOutputClass();

        $workflow = $this->buildWorkflow(
            new StructuredOutputNode(
                $this->resolveProvider(),
                $this->resolveInstructions(),
                $this->bootstrapTools(),
                $class,
                $maxRetries
            )
        );
        $handler = $workflow->start();

        /** @var AgentState $finalState */
        $finalState = $handler->getResult();

        $this->notify('structured-stop');

        // Return the structured output object
        return $finalState->get('structured_output');
    }

    /**
     * Get the output class for structured output.
     * Override this method in subclasses to provide a default output class.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify an output class.');
    }
}
