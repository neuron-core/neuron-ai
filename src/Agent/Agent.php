<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Nodes\AIProviderNode;
use NeuronAI\Agent\Nodes\RouterNode;
use NeuronAI\Agent\Nodes\StreamingAIProviderNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
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
class Agent
{
    use StaticConstructor;
    use Observable;

    protected AIProviderInterface $provider;

    protected string $instructions = '';

    /**
     * @var array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    protected array $tools = [];

    /**
     * @var ToolInterface[]
     */
    protected array $toolsBootstrapCache = [];

    protected int $toolMaxTries = 5;

    protected AgentState $state;

    protected Workflow $workflow;

    public function __construct(?AgentState $state = null)
    {
        $this->state = $state ?? new AgentState();
    }

    public function setAiProvider(AIProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
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

    /**
     * @param ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): self
    {
        $tools = \is_array($tools) ? $tools : [$tools];

        foreach ($tools as $t) {
            if (! $t instanceof ToolInterface && ! $t instanceof ToolkitInterface && ! $t instanceof ProviderToolInterface) {
                throw new AgentException('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface');
            }
            $this->tools[] = $t;
        }

        // Empty the cache for the next turn
        $this->toolsBootstrapCache = [];

        return $this;
    }

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(): array
    {
        return \array_merge($this->tools, $this->tools());
    }

    public function toolMaxTries(int $tries): self
    {
        $this->toolMaxTries = $tries;
        return $this;
    }

    public function withChatHistory(AbstractChatHistory $chatHistory): self
    {
        $this->state->setChatHistory($chatHistory);
        return $this;
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->state->getChatHistory();
    }

    /**
     * Bootstrap tools and expand toolkits.
     *
     * @return ToolInterface[]
     */
    protected function bootstrapTools(): array
    {
        if (!empty($this->toolsBootstrapCache)) {
            return $this->toolsBootstrapCache;
        }

        $this->notify('tools-bootstrapping');

        $guidelines = [];

        foreach ($this->getTools() as $tool) {
            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new \ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.\PHP_EOL.$kitGuidelines;
                }

                // Merge the tools
                $innerTools = $tool->tools();
                $this->toolsBootstrapCache = \array_merge($this->toolsBootstrapCache, $innerTools);

                // Add guidelines to the system prompt
                if ($kitGuidelines !== null && $kitGuidelines !== '' && $kitGuidelines !== '0') {
                    $kitGuidelines .= \PHP_EOL.\implode(
                        \PHP_EOL.'- ',
                        \array_map(
                            fn (ToolInterface $tool): string => "{$tool->getName()}: {$tool->getDescription()}",
                            $innerTools
                        )
                    );

                    $guidelines[] = $kitGuidelines;
                }
            } else {
                // If the item is a simple tool, add to the list as it is
                $this->toolsBootstrapCache[] = $tool;
            }
        }

        $instructions = $this->removeDelimitedContent($this->resolveInstructions(), '<TOOLS-GUIDELINES>', '</TOOLS-GUIDELINES>');
        if ($guidelines !== []) {
            $this->setInstructions(
                $instructions.\PHP_EOL.'<TOOLS-GUIDELINES>'.\PHP_EOL.\implode(\PHP_EOL.\PHP_EOL, $guidelines).\PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        return $this->toolsBootstrapCache;
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

        $tools = $this->bootstrapTools();
        $instructions = $this->resolveInstructions();

        $workflow = $this->buildWorkflow(
            new AIProviderNode($this->provider, $instructions, $tools)
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

        $tools = $this->bootstrapTools();
        $instructions = $this->resolveInstructions();

        $workflow = $this->buildWorkflow(
            new StreamingAIProviderNode($this->provider, $instructions, $tools)
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
}
