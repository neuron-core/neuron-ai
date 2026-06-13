<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Tools implement this interface to signal workflow interruptions.
 *
 * During execution, a tool's __invoke method can call setInterruptRequest()
 * to pause the workflow and request external input. The ToolNode detects
 * the interrupt request after execute() returns and throws a WorkflowInterrupt.
 *
 * On resume, setResumeRequest() is called with the user's response before
 * the tool executes again, allowing the tool to handle the resume context.
 *
 * @see HasRunKey For the similar opt-in pattern used for run tracking.
 */
interface HasInterrupt extends ToolInterface
{
    /**
     * Set an interrupt request to pause the workflow.
     *
     * Call this from within __invoke when the tool needs to interrupt
     * the workflow (e.g., for human approval).
     */
    public function setInterruptRequest(?InterruptRequest $request): void;

    /**
     * Get the interrupt request if one was set during execution.
     *
     * @return InterruptRequest|null The interrupt request, or null if none was set
     */
    public function getInterruptRequest(): ?InterruptRequest;

    /**
     * Inject a resume request before tool execution on workflow resume.
     *
     * Called by ToolNode when the workflow resumes from an interrupt.
     * The tool should check this in __invoke to handle the user's response.
     */
    public function setResumeRequest(?InterruptRequest $request): void;

    /**
     * Get the resume request injected before execution.
     *
     * @return InterruptRequest|null The resume request, or null if not resuming
     */
    public function getResumeRequest(): ?InterruptRequest;
}
