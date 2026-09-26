<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Middleware;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use stdClass;

class MiddlewareRegistrationAtomicityTest extends TestCase
{
    public function test_a_rejected_global_registration_registers_nothing(): void
    {
        $middleware = FakeMiddleware::make();
        $workflow = Workflow::make('atomic')->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        try {
            $workflow->addGlobalMiddleware([fn (): FakeMiddleware => $middleware, new stdClass()]);
            $this->fail('The invalid entry must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertSame('Middleware must be an instance of WorkflowMiddleware', $exception->getMessage());
        }

        $workflow->run();

        $middleware->assertNotCalled();
    }

    public function test_a_rejected_node_registration_registers_nothing(): void
    {
        $middleware = FakeMiddleware::make();
        $workflow = Workflow::make('atomic')->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        try {
            $workflow->addMiddleware([NodeOne::class, NodeTwo::class], [fn (): FakeMiddleware => $middleware, new stdClass()]);
            $this->fail('The invalid entry must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertSame('Middleware must be an instance of WorkflowMiddleware', $exception->getMessage());
        }

        $workflow->run();

        $middleware->assertNotCalled();
    }
}
