<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\Events\AIInferenceEvent;

use function preg_match_all;
use function str_contains;

/**
 * Detects [ACTIVATE_SKILL: name] markers in LLM text responses and activates matched skills.
 *
 * @requires AgentInterface $agent (constructor-injected) and emit() from Node
 */
trait HandleSkillActivation
{
    /**
     * Detect [ACTIVATE_SKILL: name] markers in the response content.
     * Activates each matched skill, updates the event with new instructions and tools,
     * and clears messages so the loop re-enters with fresh context.
     *
     * @return bool True if skills were activated (caller should return the event to re-enter the loop).
     */
    protected function handleSkillActivation(string $content, AIInferenceEvent $event): bool
    {
        if (!str_contains($content, '[ACTIVATE_SKILL:')) {
            return false;
        }

        preg_match_all('/\[ACTIVATE_SKILL:\s*([a-z0-9-]+)\]/', $content, $matches);

        if ($matches[1] === []) {
            return false;
        }

        foreach ($matches[1] as $skillName) {
            $this->agent?->activateSkill($skillName);
        }

        if ($this->agent !== null) {
            $event->instructions = $this->agent->composeSystemPrompt();
            $event->tools = $this->agent->bootstrapTools();
        }

        $event->setMessages();

        return true;
    }
}
