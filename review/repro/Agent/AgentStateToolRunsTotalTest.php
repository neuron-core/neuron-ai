<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentState;
use PHPUnit\Framework\TestCase;

class AgentStateToolRunsTotalTest extends TestCase
{
    public function test_tool_runs_without_a_run_key_do_not_crash(): void
    {
        $state = new AgentState();
        $state->incrementToolRun('search');
        $state->incrementToolRun('search');
        $state->incrementToolRun('lookup');

        $this->assertSame(3, $state->getToolRuns());
    }

    public function test_tool_runs_without_a_run_key_on_a_fresh_state_is_zero(): void
    {
        $this->assertSame(0, (new AgentState())->getToolRuns());
    }
}
