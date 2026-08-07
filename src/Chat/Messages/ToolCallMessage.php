<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Tools\ToolCall;
use Stringable;

use function array_map;
use function array_merge;
use function is_string;
use function json_encode;

/**
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content, ToolCall[] $tools)
 */
class ToolCallMessage extends AssistantMessage implements Stringable
{
    /**
     * @param ToolCall[] $tools
     */
    public function __construct(
        string|ContentBlockInterface|array|null $content = null,
        protected array $tools = []
    ) {
        parent::__construct($content);
    }

    /**
     * The calls carried by this message — pure conversation data (ADR 0010).
     * Execution capability lives on the agent's live tool registry, never here.
     *
     * @return ToolCall[]
     */
    public function getToolCalls(): array
    {
        return $this->tools;
    }

    /**
     * The handle to reattach to the suspended run that produced this tool call
     * (ADR 0005). Stamped by ToolNode's approval flow before suspending; an
     * opaque string here — the Chat module knows nothing about workflows.
     */
    public function setRunId(string $runId): self
    {
        $this->addMetadata('run_id', $runId);

        return $this;
    }

    public function getRunId(): ?string
    {
        // Histories stored before the runId rename carry the legacy key.
        $runId = $this->getMetadata('run_id') ?? $this->getMetadata('resume_token');

        return is_string($runId) ? $runId : null;
    }

    /**
     * @deprecated Use setRunId() instead. Will be removed in the next major version.
     */
    public function setResumeToken(string $token): self
    {
        return $this->setRunId($token);
    }

    /**
     * @deprecated Use getRunId() instead. Will be removed in the next major version.
     */
    public function getResumeToken(): ?string
    {
        return $this->getRunId();
    }

    public function jsonSerialize(): array
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'type' => 'tool_call',
                'tools' => array_map(fn (ToolCall $tool): array => $tool->jsonSerialize(), $this->tools),
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getToolCalls());
    }
}
