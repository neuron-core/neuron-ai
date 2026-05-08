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

        $this->assertSame(0, $state->getToolRuns('calculator'));

        $state->incrementToolRun('calculator');
        $this->assertSame(1, $state->getToolRuns('calculator'));

        $state->incrementToolRun('calculator');
        $this->assertSame(2, $state->getToolRuns('calculator'));
    }

    public function test_tool_attempts_reset(): void
    {
        $state = new AgentState();

        $state->incrementToolRun('calculator');
        $state->incrementToolRun('calculator');
        $this->assertSame(2, $state->getToolRuns('calculator'));

        $state->resetToolRuns();
        $this->assertSame(0, $state->getToolRuns('calculator'));
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

    public function test_tool_run_key_increment_and_retrieve(): void
    {
        $state = new AgentState();

        $this->assertSame(0, $state->getToolRunsByKey('read_file:offset=0'));

        $state->incrementToolRunByKey('read_file:offset=0');
        $this->assertSame(1, $state->getToolRunsByKey('read_file:offset=0'));

        $state->incrementToolRunByKey('read_file:offset=0');
        $this->assertSame(2, $state->getToolRunsByKey('read_file:offset=0'));
    }

    public function test_tool_run_key_different_keys_tracked_separately(): void
    {
        $state = new AgentState();

        $state->incrementToolRunByKey('read_file:offset=0');
        $state->incrementToolRunByKey('read_file:offset=0');
        $state->incrementToolRunByKey('read_file:offset=100');

        $this->assertSame(2, $state->getToolRunsByKey('read_file:offset=0'));
        $this->assertSame(1, $state->getToolRunsByKey('read_file:offset=100'));
    }

    public function test_tool_run_key_reset(): void
    {
        $state = new AgentState();

        $state->incrementToolRunByKey('read_file:offset=0');
        $state->incrementToolRunByKey('read_file:offset=0');
        $this->assertSame(2, $state->getToolRunsByKey('read_file:offset=0'));

        $state->resetToolRunsByKey();
        $this->assertSame(0, $state->getToolRunsByKey('read_file:offset=0'));
    }

    public function test_name_based_and_key_based_tracking_coexist(): void
    {
        $state = new AgentState();

        // Track by name (legacy)
        $state->incrementToolRun('read_file');
        $state->incrementToolRun('read_file');

        // Track by key (new)
        $state->incrementToolRunByKey('read_file:offset=0');
        $state->incrementToolRunByKey('read_file:offset=0');
        $state->incrementToolRunByKey('read_file:offset=100');

        $this->assertSame(2, $state->getToolRuns('read_file'));
        $this->assertSame(2, $state->getToolRunsByKey('read_file:offset=0'));
        $this->assertSame(1, $state->getToolRunsByKey('read_file:offset=100'));
    }
}
