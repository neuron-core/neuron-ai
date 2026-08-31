<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Resume;

use JsonSerializable;

final class ResumeInputResult implements JsonSerializable
{
    public function __construct(
        public readonly int $suspensionId,
        public readonly ResumeInputStatus $status,
    ) {
    }

    /** @return array{suspensionId: int, status: string} */
    public function jsonSerialize(): array
    {
        return [
            'suspensionId' => $this->suspensionId,
            'status' => $this->status->value,
        ];
    }
}
