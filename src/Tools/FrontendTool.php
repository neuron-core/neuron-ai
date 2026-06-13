<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\Interrupt\FrontendRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

use function json_encode;

/**
 * A tool that delegates execution to a frontend handler.
 *
 * On first execution, the tool signals a WorkflowInterrupt carrying a
 * FrontendRequest with a handler identifier and input payload. The
 * frontend receives the request, executes the appropriate handler
 * (e.g. a modal, a picker, etc.), and sends back the result.
 *
 * On resume, the tool receives the frontend's result and returns it
 * to the workflow.
 *
 * @example
 * <code>
 * $agent->addTool(new FrontendTool(
 *     'pick_user',
 *     'user-picker',
 *     'Open a modal to select a user',
 *     [ToolProperty::make('role', PropertyType::STRING, 'Filter by role', true)]
 * ));
 * </code>
 */
class FrontendTool extends Tool implements HasInterrupt
{
    use InterruptHandler;

    /**
     * @param string $name The tool name exposed to the LLM
     * @param string $handler Identifier for the frontend handler
     * @param string|null $description Description of the tool
     * @param ToolPropertyInterface[] $properties Tool input properties
     */
    public function __construct(
        string $name,
        protected string $handler,
        ?string $description = null,
        array $properties = [],
    ) {
        parent::__construct($name, $description, $properties);
    }

    /**
     * Get the frontend handler identifier.
     */
    public function getHandler(): string
    {
        return $this->handler;
    }

    /**
     * Execute the tool or return the frontend's response.
     *
     * If a resume request is set (frontend already handled execution),
     * return the result directly. Otherwise, signal an interrupt with
     * a FrontendRequest containing the handler and inputs.
     *
     * @return string The frontend's response or an empty string
     */
    public function __invoke(mixed ...$params): string
    {
        $resume = $this->getResumeRequest();

        if ($resume instanceof FrontendRequest) {
            return (string) json_encode($resume->getPayload());
        }

        if ($resume instanceof InterruptRequest) {
            return $resume->getMessage();
        }

        $this->setInterruptRequest(
            new FrontendRequest(
                $this->handler,
                $params,
                "Frontend tool: {$this->getName()}",
            )
        );

        return '';
    }
}
