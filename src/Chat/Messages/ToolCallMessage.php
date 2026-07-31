<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Tools\ToolInterface;
use Stringable;

use function array_map;
use function array_merge;
use function is_string;
use function json_encode;

/**
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content, ToolInterface[] $tools)
 */
class ToolCallMessage extends AssistantMessage implements Stringable
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        string|ContentBlockInterface|array|null $content = null,
        protected array $tools = []
    ) {
        parent::__construct($content);
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * The handle to reattach to the suspended run that produced this tool call
     * (ADR 0005). Stamped by the ToolApproval middleware at suspend time; an
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

    /**
     * Whether $other is a ToolCallMessage carrying the same ordered tool callIds —
     * i.e. the same logical tool call. Safe as identity because two distinct
     * ToolCallMessages can never be adjacent in a valid thread (a tool call must be
     * answered by a ToolResultMessage first); callIds are not globally unique across
     * providers (Gemini uses the tool name).
     */
    public function isSameToolCall(?Message $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }

        $ids = static fn (ToolCallMessage $message): array => array_map(
            static fn (ToolInterface $tool): ?string => $tool->getCallId(),
            $message->getTools()
        );

        return $ids($this) === $ids($other);
    }

    public function jsonSerialize(): array
    {
        return array_merge(
            parent::jsonSerialize(),
            [
                'type' => 'tool_call',
                'tools' => array_map(fn (ToolInterface $tool): array => $tool->jsonSerialize(), $this->tools),
            ]
        );
    }

    public function __toString(): string
    {
        return (string) json_encode($this->getTools());
    }
}
