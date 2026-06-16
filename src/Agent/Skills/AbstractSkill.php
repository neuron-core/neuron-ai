<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Base class for PHP-defined skills. Override methods as needed.
 */
abstract class AbstractSkill implements SkillInterface
{
    use StaticConstructor;

    public function description(): string
    {
        return '';
    }

    public function priority(): int
    {
        return 0;
    }

    public function instructions(): ?string
    {
        return null;
    }

    public function trigger(): ?string
    {
        return null;
    }

    /**
     * @return array<ToolInterface|ToolkitInterface>
     */
    public function tools(): array
    {
        return [];
    }

    public function configure(AgentInterface $agent): void
    {
    }
}
