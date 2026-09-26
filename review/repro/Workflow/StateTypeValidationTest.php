<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stub\CustomState;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class StateTypeValidationTest extends TestCase
{
    public function test_a_node_needing_a_state_the_workflow_does_not_provide_is_refused_before_any_node_runs(): void
    {
        $workflow = Workflow::make('state-type')->addNodes([
            new NodeOne(),
            new class () extends Node {
                public function __invoke(FirstEvent $event, CustomState $state): StopEvent
                {
                    return new StopEvent();
                }
            },
        ]);

        try {
            $workflow->run();
            $this->fail('The graph must refuse a node whose state type is not provided.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString(CustomState::class, $e->getMessage());
            $this->assertStringContainsString(WorkflowState::class, $e->getMessage());
        }
    }

    public function test_a_node_needing_the_provided_state_subclass_runs(): void
    {
        $state = Workflow::make('state-type-ok', state: new CustomState())->addNodes([
            new NodeOne(),
            new class () extends Node {
                public function __invoke(FirstEvent $event, CustomState $state): StopEvent
                {
                    $state->set('reached', true);
                    return new StopEvent();
                }
            },
        ])->run();

        $this->assertInstanceOf(CustomState::class, $state);
        $this->assertTrue($state->get('reached'));
    }
}
