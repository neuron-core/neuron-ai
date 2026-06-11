<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Agent\Skills\SkillActivationManager;
use NeuronAI\Agent\Skills\SkillInterface;
use NeuronAI\Agent\Skills\SkillLoader;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\SkillActivated;
use NeuronAI\Observability\Events\SkillsBootstrapped;

use function array_merge;
use function is_array;
use function usort;

use const PHP_EOL;

/**
 * Manages the skill lifecycle for an Agent: registration, bootstrapping, and activation.
 *
 * Two-tier prompt model:
 * - Tier 1 (bootstrap): Lightweight disclosure — name, description, trigger, activation hint.
 * - Tier 2 (activation): Full instructions + tools injected after the LLM decides to activate.
 *
 * Prompt is append-only — once injected, skill instructions are never removed.
 */
trait HandleSkills
{
    /**
     * @var SkillInterface[]
     */
    protected array $skills = [];

    /**
     * Collected skill instruction blocks for prompt composition.
     *
     * @var string[]
     */
    protected array $skillInstructionsCache = [];

    private ?SkillActivationManager $skillActivationManager = null;

    /**
     * Override to declare skills for this agent.
     *
     * @return SkillInterface[]
     */
    protected function skills(): array
    {
        return [];
    }

    /**
     * @return SkillInterface[]
     */
    public function getSkills(): array
    {
        return $this->collectSkills();
    }

    /**
     * @return string[]
     */
    public function getSkillInstructions(): array
    {
        return $this->skillInstructionsCache;
    }

    /**
     * @param SkillInterface|SkillInterface[] $skills
     *
     * @throws AgentException
     */
    public function addSkill(SkillInterface|array $skills): AgentInterface
    {
        $skills = is_array($skills) ? $skills : [$skills];

        foreach ($skills as $skill) {
            if (!$skill instanceof SkillInterface) {
                throw new AgentException('Skills must implement '.SkillInterface::class);
            }
            $this->skills[] = $skill;
        }

        // Clear caches so everything gets re-processed.
        $this->toolsBootstrapCache = [];
        $this->skillInstructionsCache = [];

        return $this;
    }

    /**
     * Register skills from parent directories containing skill subdirectories.
     * Each path is scanned for subdirectories that contain a SKILL.md file.
     * When skill names collide across directories, later directories take precedence.
     *
     * @param string[] $paths
     */
    public function addSkillDirectory(array $paths): AgentInterface
    {
        $skills = SkillLoader::discover($paths);
        if ($skills !== []) {
            $this->addSkill($skills);
        }

        return $this;
    }

    /**
     * Register skills from individual skill directories, each containing a SKILL.md.
     * When skill names collide, later paths take precedence.
     *
     * @param string[] $paths
     */
    public function addSkillPaths(array $paths): AgentInterface
    {
        $skills = SkillLoader::loadPaths($paths);
        if ($skills !== []) {
            $this->addSkill($skills);
        }

        return $this;
    }

    /**
     * Bootstrap all skills through the lifecycle stages.
     * Only registers instructions and configures skills; does NOT register tools.
     * Tools are activated lazily when the LLM activates a skill.
     */
    public function bootstrapSkills(): void
    {
        $this->skillInstructionsCache = [];

        $skills = $this->collectSkills();

        if ($skills === []) {
            return;
        }

        EventBus::emit(
            'skills-bootstrapping',
            $this,
            null,
            $this->workflowId
        );

        $this->applySkillInstructions($skills);
        $this->configureSkills($skills);

        EventBus::emit(
            'skills-bootstrapped',
            $this,
            new SkillsBootstrapped(
                $skills,
                $this->skillInstructionsCache,
            ),
            $this->workflowId
        );
    }

    /**
     * Activate a skill by name.
     * Records activation, adds skill tools to the agent pool,
     * and appends skill instructions as a context block.
     * Returns true if newly activated, false if already active.
     */
    public function activateSkill(string $skillName): bool
    {
        if (!$this->getSkillActivationManager()->activate($skillName)) {
            return false;
        }

        $skills = $this->collectSkills();
        $skill = null;
        foreach ($skills as $s) {
            if ($s->name() === $skillName) {
                $skill = $s;
                break;
            }
        }

        if ($skill === null) {
            return false;
        }

        // Add skill tools to the agent tool pool
        $skillTools = $skill->tools();
        if ($skillTools !== []) {
            $this->addTool($skillTools);
        }

        // Append full skill instructions as a context block
        $instructions = $skill->instructions();
        $block = '<skill name="'.$skillName.'">';
        if ($instructions !== null && $instructions !== '') {
            $block .= PHP_EOL.$instructions;
        }
        $block .= PHP_EOL.'</skill>';
        $this->skillInstructionsCache[] = $block;

        EventBus::emit(
            'skill-activated',
            $this,
            new SkillActivated($skillName, 'LLM-initiated activation'),
            $this->workflowId,
        );

        return true;
    }

    /**
     * Get the activation manager, lazily initialized.
     */
    public function getSkillActivationManager(): SkillActivationManager
    {
        if ($this->skillActivationManager === null) {
            $this->skillActivationManager = new SkillActivationManager();
        }

        return $this->skillActivationManager;
    }

    /**
     * Merge runtime-added skills with the skills() override, sorted by priority.
     *
     * @return SkillInterface[]
     */
    protected function collectSkills(): array
    {
        $skills = array_merge($this->skills, $this->skills());

        usort($skills, fn (SkillInterface $a, SkillInterface $b): int => $a->priority() <=> $b->priority());

        return $skills;
    }

    /**
     * Collect skill instruction blocks into the internal cache.
     * Does NOT mutate the system prompt — that happens in composeSystemPrompt().
     *
     * @param SkillInterface[] $skills
     */
    protected function applySkillInstructions(array $skills): void
    {
        foreach ($skills as $skill) {
            $description = $skill->description();
            $instructions = $skill->instructions();
            $trigger = $skill->trigger();
            $skillTools = $skill->tools();

            if ($skillTools !== []) {
                // Skills with tools: Tier 1 = name + description + trigger + activation hint.
                // Full instructions only appear after activation (Tier 2).
                $block = '# '.$skill->name();
                if ($description !== '') {
                    $block .= ': '.$description;
                }
                if ($trigger !== null) {
                    $block .= PHP_EOL.'When to use: '.$trigger;
                }
                $block .= PHP_EOL.PHP_EOL.'When you need to use this skill, respond with [ACTIVATE_SKILL: '.$skill->name().']';
                $this->skillInstructionsCache[] = $block;
            } elseif ($description !== '' || $instructions !== null) {
                // Skills without tools: include instructions directly — no activation needed.
                $block = '# '.$skill->name();
                if ($description !== '') {
                    $block .= ': '.$description;
                }
                if ($instructions !== null && $instructions !== '') {
                    $block .= PHP_EOL.$instructions;
                }
                $this->skillInstructionsCache[] = $block;
            }
        }
    }

    /**
     * Call configure() on each skill, allowing them to modify agent behavior.
     *
     * @param SkillInterface[] $skills
     */
    protected function configureSkills(array $skills): void
    {
        foreach ($skills as $skill) {
            $skill->configure($this);
        }
    }
}
