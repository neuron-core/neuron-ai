<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\HandleContent;
use NeuronAI\Observability\ConsoleDebugObserver;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use Throwable;

use function is_array;

use const PHP_EOL;

/**
 * @method AgentStartEvent resolveStartEvent()
 * @method AgentState resolveState()
 * @method AgentState run()
 */
class Agent extends Workflow implements AgentInterface
{
    use HandleAgentState;
    use ResolveProvider;
    use HandleTools;
    use HandleContent;
    use HandleInstructions;
    use HandleSkills;

    protected bool $parallelToolCalls = false;

    private bool $debugObserverAttached = false;

    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface
    {
        $this->resolveState()->resetToolRuns();
        $this->resolveState()->resetSteps();

        return parent::init($resumeRequest);
    }

    /**
     * Enable debug mode to print all LLM interactions to the console.
     * Can also be enabled globally via NEURON_DEBUG=true environment variable.
     */
    public function debug(bool $enabled = true): AgentInterface
    {
        if ($enabled && !$this->debugObserverAttached) {
            $this->observe(new ConsoleDebugObserver());
            $this->debugObserverAttached = true;
        }

        return $this;
    }

    protected function ensureDebugObserver(): void
    {
        if (getenv('NEURON_DEBUG') === 'true') {
            $this->debug();
        }
    }

    /**
     * Determines whether tools should be executed in parallel.
     * Override this method to return true to enable parallel tool execution.
     *
     * Note: Parallel execution requires the pcntl extension and spatie/fork package.
     */
    public function parallelToolCalls(bool $enabled): AgentInterface
    {
        $this->parallelToolCalls = $enabled;
        return $this;
    }

    /**
     * Prepare the agent workflow with mode-specific nodes.
     * Since Agent extends Workflow, we configure the current instance.
     *
     * @param Node|Node[] $nodes Mode-specific nodes (ChatNode, StreamingNode, etc.)
     */
    protected function compose(array|Node $nodes): void
    {
        if ($this->eventNodeMap !== []) {
            // it's already been bootstrapped
            return;
        }

        $nodes = is_array($nodes) ? $nodes : [$nodes];

        // Select the appropriate ToolNode based on the parallel execution setting
        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new ToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler());

        // Add nodes to the workflow instance
        $this->addNodes([
            ...$nodes,
            $toolNode,
        ]);
    }

    /**
     * @throws InspectorException
     */
    protected function startEvent(): AgentStartEvent
    {
        // Bootstrap skills first so their tools and configuration are available
        $this->bootstrapSkills();
        // The bootstrapTools method resolves all tools (including skill-provided ones)
        // and injects toolkit guidelines into the instructions
        $tools = $this->bootstrapTools();
        // Compose the final system prompt from all instruction sources
        $instructions = $this->composeSystemPrompt();

        return new AIInferenceEvent($instructions, $tools);
    }

    /**
     * Compose the final system prompt from all instruction providers.
     * This is a pure computation — it does not mutate internal state.
     */
    public function composeSystemPrompt(): string
    {
        $prompt = $this->resolveInstructions();

        $skillInstructions = $this->getSkillInstructions();
        if ($skillInstructions !== []) {
            $prompt = $this->removeDelimitedContent(
                $prompt,
                '<SKILL-GUIDELINES>',
                '</SKILL-GUIDELINES>'
            );

            $prompt .= PHP_EOL.'<SKILL-GUIDELINES>'
                .PHP_EOL.implode(PHP_EOL.PHP_EOL, $skillInstructions)
                .PHP_EOL.'</SKILL-GUIDELINES>';
        }

        return $prompt;
    }

    /**
     * @param Message|Message[] $messages
     */
    public function chat(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->ensureDebugObserver();

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Prepare the workflow for chat mode
        $this->compose(
            new ChatNode($this->resolveProvider(), $this),
        );

        return new AgentHandler($this, $interrupt);
    }

    /**
     * Stream agent response
     *
     * @param Message|Message[] $messages
     */
    public function stream(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->ensureDebugObserver();

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        // Prepare the workflow for streaming mode
        $this->compose(
            new StreamingNode($this->resolveProvider(), $this),
        );

        return new AgentHandler($this, $interrupt);
    }

    /**
     * @param Message|Message[] $messages
     * @throws AgentException
     * @throws Throwable
     */
    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?InterruptRequest $interrupt = null
    ): mixed {
        $this->ensureDebugObserver();

        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $class ??= $this->getOutputClass();

        // Prepare workflow nodes for structured output mode
        $this->compose(
            new StructuredOutputNode($this->resolveProvider(), $class, $maxRetries),
        );

        $handler = parent::init($interrupt);

        /** @var AgentState $finalState */
        $finalState = $handler->run();

        // Return the structured output object
        return $finalState->get('structured_output');
    }

    /**
     * Get the class representing the structured output.
     * Override this method in subclasses to provide a default output class.
     *
     * @throws AgentException
     */
    protected function getOutputClass(): string
    {
        throw new AgentException('You need to specify a structured output class.');
    }
}
