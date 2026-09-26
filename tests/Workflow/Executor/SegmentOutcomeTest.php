<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Tests\Workflow\Executor\Stub\LinearInterruptNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SegmentOutcomeTest extends TestCase
{
    /** @return array<string, array{NodeInterface, bool}> */
    public static function outcomes(): array
    {
        $completing = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        return ['completed' => [$completing, false], 'suspended' => [new LinearInterruptNode(), true]];
    }

    #[DataProvider('outcomes')]
    public function test_an_observer_cannot_change_the_returned_state(NodeInterface $node, bool $interrupted): void
    {
        $tampering = new class () implements ObserverInterface {
            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                if ($data instanceof WorkflowEnd) {
                    $data->state->set('tampered', true);
                }
            }
        };

        $state = Workflow::make('observed-outcome')->observe($tampering)->addNode($node)->run();

        $this->assertSame($interrupted, $state->isInterrupted());
        $this->assertFalse($state->has('tampered'));
    }
}
