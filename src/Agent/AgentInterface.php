<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Skills\SkillInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

interface AgentInterface
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface;

    public function resolveProvider(): AIProviderInterface;

    public function setInstructions(string $instructions): AgentInterface;

    public function resolveInstructions(): string;

    /**
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @param SkillInterface|SkillInterface[] $skills
     */
    public function addSkill(SkillInterface|array $skills): AgentInterface;

    /**
     * Register skills from parent directories containing skill subdirectories.
     * Each path is scanned for subdirectories that contain a SKILL.md file.
     * When skill names collide across directories, later directories take precedence.
     *
     * @param string[] $paths
     */
    public function addSkillDirectory(array $paths): AgentInterface;

    /**
     * Register skills from individual skill directories, each containing a SKILL.md.
     * When skill names collide, later paths take precedence.
     *
     * @param string[] $paths
     */
    public function addSkillPaths(array $paths): AgentInterface;

    /**
     * Activate a skill by name.
     * Records activation, adds skill tools, and appends skill instructions.
     * Returns true if newly activated, false if already active.
     */
    public function activateSkill(string $skillName): bool;

    /**
     * Compose the final system prompt from all instruction sources.
     */
    public function composeSystemPrompt(): string;

    /**
     * Enable debug mode to print all LLM interactions to the console.
     */
    public function debug(bool $enabled = true): AgentInterface;

    /**
     * @return SkillInterface[]
     */
    public function getSkills(): array;

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;

    /**
     * Bootstrap all tools, expanding toolkits into individual ToolInterface instances.
     *
     * @return ToolInterface[]
     */
    public function bootstrapTools(): array;

    public function setChatHistory(AbstractChatHistory $chatHistory): AgentInterface;

    /**
     * @param Message|Message[] $messages
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler;

    /**
     * @param Message|Message[] $messages
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $interrupt = null): mixed;
}
