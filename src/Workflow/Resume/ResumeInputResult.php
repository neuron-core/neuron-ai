<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Resume;

use JsonSerializable;

final class ResumeInputResult implements JsonSerializable
{
    public function __construct(
        public readonly int $interruptId,
        public readonly ResumeInputStatus $status,
    ) {
    }

    /** @return array{interruptId: int, status: string} */
    public function jsonSerialize(): array
    {
        return [
            'interruptId' => $this->interruptId,
            'status' => $this->status->value,
        ];
    }
}
