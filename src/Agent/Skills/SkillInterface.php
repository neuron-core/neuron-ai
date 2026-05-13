<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * A skill is a declarative data package: instructions + tools + metadata.
 * Skills never control execution flow — the LLM decides when to activate them.
 */
interface SkillInterface
{
    /**
     * Unique skill identifier. Must be lowercase kebab-case (e.g. "web-search").
     */
    public function name(): string;

    /**
     * One-line summary of what this skill does. Shown in Tier 1 disclosure.
     */
    public function description(): string;

    /**
     * Execution priority. Lower values = higher priority. Default 0.
     */
    public function priority(): int;

    /**
     * Full instructions injected after activation (Tier 2).
     * Return null if the skill has no body instructions.
     */
    public function instructions(): ?string;

    /**
     * Short description telling the LLM when to activate this skill.
     * Shown in Tier 1 disclosure before activation. Return null if not applicable.
     */
    public function trigger(): ?string;

    /**
     * Tools provided by this skill. Only registered after activation.
     *
     * @return array<ToolInterface|ToolkitInterface>
     */
    public function tools(): array;

    /**
     * Lifecycle hook to modify agent behavior during bootstrapping.
     * Called after instructions have been collected, before the first LLM call.
     * Use this to add tools, set chat history, etc. — NOT to replace instructions.
     */
    public function configure(AgentInterface $agent): void;
}
