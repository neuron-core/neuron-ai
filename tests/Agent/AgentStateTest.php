<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentState;
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

    public function test_tool_runs_with_key_increment_and_retrieve(): void
    {
        $state = new AgentState();

        $this->assertSame(0, $state->getToolRuns('read_file:offset=0'));

        $state->incrementToolRun('read_file:offset=0');
        $this->assertSame(1, $state->getToolRuns('read_file:offset=0'));

        $state->incrementToolRun('read_file:offset=0');
        $this->assertSame(2, $state->getToolRuns('read_file:offset=0'));
    }

    public function test_different_keys_tracked_separately(): void
    {
        $state = new AgentState();

        $state->incrementToolRun('read_file:offset=0');
        $state->incrementToolRun('read_file:offset=0');
        $state->incrementToolRun('read_file:offset=100');

        $this->assertSame(2, $state->getToolRuns('read_file:offset=0'));
        $this->assertSame(1, $state->getToolRuns('read_file:offset=100'));
    }

    public function test_tool_runs_reset(): void
    {
        $state = new AgentState();

        $state->incrementToolRun('read_file:offset=0');
        $state->incrementToolRun('read_file:offset=0');
        $this->assertSame(2, $state->getToolRuns('read_file:offset=0'));

        $state->resetToolRuns();
        $this->assertSame(0, $state->getToolRuns('read_file:offset=0'));
    }
}
