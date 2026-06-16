<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Skills;

use NeuronAI\Agent\Skills\SkillActivationManager;
use PHPUnit\Framework\TestCase;

class SkillActivationManagerTest extends TestCase
{
    public function test_activate_returns_true_for_new_skill(): void
    {
        $manager = new SkillActivationManager();
        $this->assertTrue($manager->activate('weather'));
    }

    public function test_activate_returns_false_for_duplicate(): void
    {
        $manager = new SkillActivationManager();
        $manager->activate('weather');
        $this->assertFalse($manager->activate('weather'));
    }

    public function test_is_active_returns_false_before_activation(): void
    {
        $manager = new SkillActivationManager();
        $this->assertFalse($manager->isActive('weather'));
    }

    public function test_is_active_returns_true_after_activation(): void
    {
        $manager = new SkillActivationManager();
        $manager->activate('weather');
        $this->assertTrue($manager->isActive('weather'));
    }

    public function test_get_active_names_returns_all_activated(): void
    {
        $manager = new SkillActivationManager();
        $manager->activate('weather');
        $manager->activate('calculator');

        $names = $manager->getActiveNames();
        sort($names);
        $this->assertSame(['calculator', 'weather'], $names);
    }

    public function test_multiple_skills_tracked_independently(): void
    {
        $manager = new SkillActivationManager();
        $manager->activate('weather');
        $manager->activate('calculator');

        $this->assertTrue($manager->isActive('weather'));
        $this->assertTrue($manager->isActive('calculator'));
        $this->assertFalse($manager->isActive('search'));
    }

    public function test_get_active_names_empty_when_none_activated(): void
    {
        $manager = new SkillActivationManager();
        $this->assertSame([], $manager->getActiveNames());
    }
}
