<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use PHPUnit\Framework\TestCase;

class AgentStateTest extends TestCase
{
    public function test_tool_attempts_increment_and_retrieve(): void
    {
        $state = new AgentState();

        $this->assertSame(0, $state->getToolAttempts('calculator'));

        $state->incrementToolAttempt('calculator');
        $this->assertSame(1, $state->getToolAttempts('calculator'));

        $state->incrementToolAttempt('calculator');
        $this->assertSame(2, $state->getToolAttempts('calculator'));
    }

    public function test_tool_attempts_reset(): void
    {
        $state = new AgentState();

        $state->incrementToolAttempt('calculator');
        $state->incrementToolAttempt('calculator');
        $this->assertSame(2, $state->getToolAttempts('calculator'));

        $state->resetToolAttempts();
        $this->assertSame(0, $state->getToolAttempts('calculator'));
    }

    public function test_add_and_get_steps(): void
    {
        $state = new AgentState();

        $this->assertEmpty($state->getSteps());

        $state->addStep(new AssistantMessage('Step 1'));
        $state->addStep(new AssistantMessage('Step 2'));

        $steps = $state->getSteps();
        $this->assertCount(2, $steps);
        $this->assertSame('Step 1', $steps[0]->getContent());
        $this->assertSame('Step 2', $steps[1]->getContent());
    }

    public function test_reset_steps(): void
    {
        $state = new AgentState();

        $state->addStep(new AssistantMessage('Step 1'));
        $state->addStep(new AssistantMessage('Step 2'));

        $this->assertCount(2, $state->getSteps());

        $state->resetSteps();
        $this->assertEmpty($state->getSteps());
    }
}
