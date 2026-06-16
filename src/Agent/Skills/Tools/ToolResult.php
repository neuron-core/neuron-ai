<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools;

/**
 * Structured result from a tool executor.
 */
class ToolResult
{
    public function __construct(
        public readonly int $exitCode = 0,
        public readonly string $output = '',
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0 && $this->error === null;
    }

    public function toArray(): array
    {
        $result = [
            'exit_code' => $this->exitCode,
            'output' => $this->output,
        ];

        if ($this->error !== null) {
            $result['error'] = $this->error;
        }

        if ($this->metadata !== []) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }
}
