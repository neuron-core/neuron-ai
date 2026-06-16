<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Agent\Skills\SkillInterface;

class SkillsBootstrapped
{
    /**
     * @param SkillInterface[] $skills
     * @param string[] $instructions
     */
    public function __construct(
        public array $skills,
        public array $instructions = [],
    ) {
    }
}
