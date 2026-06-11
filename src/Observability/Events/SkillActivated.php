<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class SkillActivated
{
    public function __construct(
        public readonly string $skillName,
        public readonly ?string $reason = null,
    ) {}
}
