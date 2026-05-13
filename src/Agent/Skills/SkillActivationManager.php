<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills;

/**
 * Lightweight activation tracker that records which skills have been activated.
 * Used for deduplication — prevents re-injecting the same skill's tools and instructions.
 */
class SkillActivationManager
{
    /** @var array<string, true> */
    private array $active = [];

    /**
     * Mark a skill as activated.
     *
     * @return bool True if newly activated, false if already active.
     */
    public function activate(string $skillName): bool
    {
        if (isset($this->active[$skillName])) {
            return false;
        }

        $this->active[$skillName] = true;
        return true;
    }

    /**
     * Check whether a skill has been activated.
     */
    public function isActive(string $skillName): bool
    {
        return isset($this->active[$skillName]);
    }

    /**
     * Get all activated skill names.
     *
     * @return string[]
     */
    public function getActiveNames(): array
    {
        return array_keys($this->active);
    }
}
