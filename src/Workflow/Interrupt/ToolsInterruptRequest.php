<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use function array_map;
use function count;
use function serialize;

/**
 * An interrupt request that wraps one or more tool-specific interrupt requests.
 *
 * Used as the single envelope for all tool-triggered interrupts:
 * - Single tool execution: wraps one tool's request, keyed by tool name
 * - Parallel execution: merges multiple tools' requests into one envelope
 *
 * On resume, ToolNode extracts each tool's sub-request by tool name
 * and injects it via HasInterrupt::setResumeRequest().
 */
class ToolsInterruptRequest extends InterruptRequest
{
    /**
     * @var array<string, InterruptRequest> Interrupt requests indexed by tool name
     */
    private array $tools = [];

    /**
     * Add a tool's interrupt request.
     */
    public function addRequest(string $toolName, InterruptRequest $request): void
    {
        $this->tools[$toolName] = $request;
    }

    /**
     * Get a specific tool's interrupt request.
     */
    public function getRequest(string $toolName): ?InterruptRequest
    {
        return $this->tools[$toolName] ?? null;
    }

    /**
     * Get all tool interrupt requests.
     *
     * @return array<string, InterruptRequest>
     */
    public function getRequests(): array
    {
        return $this->tools;
    }

    /**
     * Check if any tools have interrupt requests.
     */
    public function hasRequests(): bool
    {
        return $this->tools !== [];
    }

    /**
     * Get the number of tools with interrupt requests.
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'tools' => array_map(serialize(...), $this->tools),
        ];
    }
}
