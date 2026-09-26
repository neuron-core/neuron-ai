<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Observability\MiddlewareEnd;
use NeuronAI\Workflow\Observability\MiddlewareStart;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Span-based listeners (APM, tracing) open a span on every start event and
 * close it on the matching end, like WorkflowEnd and BranchEnd already do on
 * every exit path.
 */
class NodeSpanPairingTest extends TestCase
{
    protected int $openNodes = 0;

    protected int $openMiddleware = 0;

    protected function traced(Workflow $workflow): Workflow
    {
        return $workflow->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            match (true) {
                $event instanceof WorkflowNodeStart => $this->openNodes++,
                $event instanceof WorkflowNodeEnd => $this->openNodes--,
                $event instanceof MiddlewareStart => $this->openMiddleware++,
                $event instanceof MiddlewareEnd => $this->openMiddleware--,
                default => null,
            };
        });
    }

    public function test_a_suspending_node_closes_its_span(): void
    {
        $workflow = $this->traced(Workflow::make('traced')->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]));

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame(0, $this->openNodes);
    }

    public function test_a_failing_node_closes_its_span(): void
    {
        $workflow = $this->traced(Workflow::make('traced')->addNode(new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                throw new RuntimeException('node exploded');
            }
        }));

        try {
            $workflow->run();
            $this->fail('The node failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('node exploded', $e->getMessage());
        }

        $this->assertSame(0, $this->openNodes);
    }

    public function test_a_failing_middleware_closes_its_span(): void
    {
        $workflow = $this->traced(
            Workflow::make('traced')
                ->addGlobalMiddleware(FakeMiddleware::make()->setThrowOnBefore(new RuntimeException('middleware exploded')))
                ->addNode(new class () extends Node {
                    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
                    {
                        return new StopEvent();
                    }
                })
        );

        try {
            $workflow->run();
            $this->fail('The middleware failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('middleware exploded', $e->getMessage());
        }

        $this->assertSame(0, $this->openMiddleware);
        $this->assertSame(0, $this->openNodes);
    }
}
