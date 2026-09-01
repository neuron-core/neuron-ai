<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\ToolInterface;

/**
 * Direct ToolInterface implementor with an input-derived run key, for testing
 * per-parameter run tracking. A named class (not anonymous) so instances clone
 * cleanly when the node resolves calls against the registry.
 */
class TestParametrizedTool implements ToolInterface
{
    private ?string $callId = null;
    private array $inputs = [];
    private string $description = 'A parameterized test tool';
    private bool $visible = true;
    private ?int $maxRuns = null;

    public function __construct(
        private readonly string $name
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): ToolInterface
    {
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): ToolInterface
    {
        $this->description = $description ?? '';
        return $this;
    }

    public function addProperty(\NeuronAI\Tools\ToolPropertyInterface $property): ToolInterface
    {
        return $this;
    }

    public function getProperties(): array
    {
        return [];
    }

    public function getRequiredProperties(): array
    {
        return [];
    }

    public function getParameters(): array
    {
        return [];
    }

    public function getInputs(): array
    {
        return $this->inputs;
    }

    public function getInput(string $key): mixed
    {
        return $this->inputs[$key] ?? null;
    }

    public function setInputs(array $inputs): ToolInterface
    {
        $this->inputs = $inputs;
        return $this;
    }

    public function getCallId(): ?string
    {
        return $this->callId;
    }

    public function setCallId(string $callId): ToolInterface
    {
        $this->callId = $callId;
        return $this;
    }

    public function hasResult(): bool
    {
        return true;
    }

    public function getResult(): string
    {
        return 'executed';
    }

    public function setResult(mixed $result): ToolInterface
    {
        return $this;
    }

    public function getMaxRuns(): ?int
    {
        return $this->maxRuns;
    }

    public function setMaxRuns(int $tries): ToolInterface
    {
        $this->maxRuns = $tries;
        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function visible(bool $visible): ToolInterface
    {
        $this->visible = $visible;
        return $this;
    }

    public function setCallable(callable $callback): ToolInterface
    {
        return $this;
    }

    public function execute(): void
    {
        // Tool execution logic
    }

    public function getRunKey(): string
    {
        return $this->name . ':' . ($this->inputs['key'] ?? '');
    }

    public function requiresApproval(array $inputs): bool|string
    {
        return false;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
