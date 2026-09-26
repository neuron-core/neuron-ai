<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The workflow ID check refuses control characters, but its '$' anchor (no D
 * modifier) matches before a final line feed, so a single trailing "\n"
 * slips through while the same character anywhere else is refused.
 */
class WorkflowIdTrailingNewlineTrustBoundarySecurityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function trailingLineFeeds(): iterable
    {
        yield 'trailing line feed' => ["order-42\n"];
        yield 'thread name with a trailing line feed' => ["thread\n"];
        yield '255 characters plus a trailing line feed' => [str_repeat('a', 255) . "\n"];
    }

    #[DataProvider('trailingLineFeeds')]
    public function test_a_trailing_line_feed_is_refused_like_any_other_control_character(string $workflowId): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::never())->method('get');
        $persistence->expects(self::never())->method('initializeIfAbsent');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid workflow ID: use a nonempty address of at most 255 characters without control characters or the __ prefix.');

        (new WorkflowEngine($persistence))->admit($workflowId, ExecutionRequest::start(new StartEvent()), new WorkflowState(), null, false);
    }

    public function test_a_trailing_line_feed_is_refused_on_inspection(): void
    {
        $persistence = $this->createMock(PersistenceInterface::class);
        $persistence->expects(self::never())->method('get');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid workflow ID');

        (new WorkflowEngine($persistence))->inspect("order-42\n");
    }
}
