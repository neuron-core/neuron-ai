<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class MemoizingNode extends CountableNode
{
    protected static int $operationCount = 0;

    public static function getOperationCount(): int
    {
        return self::$operationCount;
    }

    public static function resetOperationCount(): void
    {
        self::$operationCount = 0;
    }

    public function __construct(protected bool $shouldCrash = false)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->recordExecution();

        // Expensive/non-deterministic work wrapped in a durable memo.
        $value = $this->memoize('expensive_op', function (): string {
            self::$operationCount++;
            return 'computed_' . self::$operationCount;
        });

        $state->set('memo_result', $value);

        // Crash AFTER the memo has been persisted — on recovery the memo must not re-run.
        if ($this->shouldCrash) {
            throw new RuntimeException('Simulated crash after memoize');
        }

        return new StopEvent(result: $value);
    }
}
