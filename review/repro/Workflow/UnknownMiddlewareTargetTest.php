<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Middleware;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function count;

class UnknownMiddlewareTargetTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_middleware_registered_for_unknown_class_fails_loudly(): void
    {
        $guard = FakeMiddleware::make();

        $workflow = Workflow::make('test-workflow')
            ->addMiddleware('NeuronAI\Tests\Workflow\Stub\NodeOen', fn (): FakeMiddleware => $guard)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        try {
            $this->execute($workflow);
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('NeuronAI\Tests\Workflow\Stub\NodeOen', $e->getMessage());
            $guard->assertCallCount(0);
            return;
        }

        $this->fail('Middleware registered for a non-existent class was silently accepted and never ran (before calls: ' . count($guard->getBeforeRecords()) . ').');
    }
}
