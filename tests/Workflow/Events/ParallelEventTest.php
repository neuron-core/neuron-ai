<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Events;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\TestCase;

class ParallelEventTest extends TestCase
{
    public function test_named_branches_preserve_repeated_event_types(): void
    {
        $first = new StartEvent();
        $second = new StartEvent();

        $event = new ParallelEvent([
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertSame([
            'first' => $first,
            'second' => $second,
        ], $event->branches);
    }

    /**
     * @dataProvider invalidBranchesProvider
     * @param array<array-key, StartEvent> $branches
     */
    public function test_unnamed_branches_are_rejected(array $branches): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Parallel branches must use non-empty string names.');

        new ParallelEvent($branches);
    }

    /** @return array<string, array{array<array-key, StartEvent>}> */
    public static function invalidBranchesProvider(): array
    {
        return [
            'list' => [[new StartEvent()]],
            'integer key' => [[2 => new StartEvent()]],
            'empty name' => [['' => new StartEvent()]],
        ];
    }
}
