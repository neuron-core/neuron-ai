<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\Tools\ToolRejectionHandler;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class ToolApproval implements WorkflowMiddleware
{
    /**
     * @param string[] $toolsRequiringApproval Tool names that require approval (empty = all tools)
     */
    public function __construct(
        protected array $toolsRequiringApproval = []
    ) {
    }

    /**
     * Execute before the node runs.
     *
     * On initial run: Inspects tools and creates interrupt request for approval.
     * On resume: Processes human decisions and modifies tools accordingly.
     *
     * @param ToolNode $node
     * @param ToolCallEvent $event
     * @param AgentState $state
     * @throws WorkflowInterrupt
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }

        // Check if we're resuming with decisions
        if ($node->isResuming() && $node->getResumeRequest() instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
            $this->processDecisions($node->getResumeRequest(), $event);
            return;
        }

        // Initial run: Check if any tools require approval
        $toolsToApprove = $this->filterToolsRequiringApproval($event->toolCallMessage->getTools());

        if ($toolsToApprove === []) {
            // No tools require approval, continue execution
            return;
        }

        // Create the interrupt request with actions for each tool
        $actions = [];
        foreach ($toolsToApprove as $tool) {
            $actions[] = $this->createAction($tool);
        }

        $count = \count($actions);
        $interruptRequest = new InterruptRequest(
            actions: $actions,
            reason: \sprintf(
                '%d tool call%s require%s human approval before execution',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : ''
            )
        );

        throw new WorkflowInterrupt($interruptRequest, $node, $state, $event);
    }

    /**
     * Execute after the node runs.
     *
     * Cleanup: Remove tool-action mapping from state after execution.
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
    {
        //
    }

    /**
     * Filter tools that require approval based on configuration.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function filterToolsRequiringApproval(array $tools): array
    {
        if ($this->toolsRequiringApproval === []) {
            // Empty array means all tools require approval
            return $tools;
        }

        return \array_filter(
            $tools,
            fn (ToolInterface $tool): bool => \in_array($tool->getName(), $this->toolsRequiringApproval, true)
        );
    }

    /**
     * Create an Action for a tool that requires approval.
     */
    protected function createAction(ToolInterface $tool): Action
    {
        $inputs = $tool->getInputs();
        $inputsDescription = $inputs === []
            ? '(no arguments)'
            : \json_encode($inputs, \JSON_PRETTY_PRINT);

        return new Action(
            id: $tool->getCallId() ?? \uniqid('tool_'),
            name: $tool->getName(),
            description: \sprintf(
                "Description: %s\nInputs: %s",
                $tool->getDescription() ?? 'No description',
                $inputsDescription
            ),
            decision: ActionDecision::Pending,
            feedback: null
        );
    }

    /**
     * Process human decisions and modify tools accordingly.
     *
     * This method modifies the tools in-place based on human decisions:
     *  - Rejected: Tool callback is replaced to return rejection message
     *  - Edited: Tool inputs are modified
     *  - Approved: No changes, tool executes normally
     */
    protected function processDecisions(
        InterruptRequest $request,
        ToolCallEvent $event,
    ): void {
        /** @var array<string, Action> $actions */
        $actions = \array_reduce($request->actions, function (array $carry, Action $action): array {
            $carry[$action->id] = $action;
            return $carry;
        }, []);

        foreach ($event->toolCallMessage->getTools() as $tool) {
            $toolCallId = $tool->getCallId();
            if ($toolCallId === null) {
                // Tool doesn't require approval, skip
                continue;
            }
            if (!isset($actions[$toolCallId])) {
                // Tool doesn't require approval, skip
                continue;
            }

            $action = $actions[$toolCallId];

            // Process based on decision
            if ($action->isRejected()) {
                $this->handleRejectedTool($tool, $action);
            }

            // If approved, do nothing - the tool will be executed normally
        }
    }

    /**
     * Handle a rejected tool by replacing its callback with a rejection message.
     *
     * This prevents the tool from executing its actual logic and instead
     * returns a human-readable rejection message that the AI can process.
     *
     * Uses ToolRejectionHandler instead of a closure to ensure serializability
     * when workflows are interrupted multiple times.
     */
    protected function handleRejectedTool(ToolInterface $tool, Action $action): void
    {
        $rejectionMessage = \sprintf(
            "The user rejected the tool '%s' execution. Reason: %s",
            $tool->getName(),
            $action->feedback ?? 'No reason provided'
        );

        // Replace the tool's callback with a serializable rejection handler
        $tool->setCallable(new ToolRejectionHandler($rejectionMessage));
    }
}
