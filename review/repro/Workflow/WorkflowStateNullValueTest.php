<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class WorkflowStateNullValueTest extends TestCase
{
    public function test_state_returns_a_stored_null_instead_of_the_default(): void
    {
        $state = new WorkflowState();
        $state->set('approved_by', null);

        $this->assertTrue($state->has('approved_by'));
        $this->assertNull($state->get('approved_by', 'nobody'));
        $this->assertSame('nobody', $state->get('missing', 'nobody'));
    }

    public function test_resources_return_a_stored_null_instead_of_the_default(): void
    {
        $resources = new WorkflowResources();
        $resources->set('logger', null);

        $this->assertTrue($resources->has('logger'));
        $this->assertNull($resources->get('logger', 'fallback'));
        $this->assertSame('fallback', $resources->get('missing', 'fallback'));
    }
}
