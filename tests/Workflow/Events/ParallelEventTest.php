<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Events;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

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

    /** @param array<array-key, StartEvent> $branches */
    #[DataProvider('invalidBranchesProvider')]
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
            'one unnamed among named' => [['named' => new StartEvent(), new StartEvent()]],
        ];
    }

    public function test_branch_names_keep_whitespace_and_unicode(): void
    {
        $event = new ParallelEvent([' ' => new StartEvent(), 'ramo-è' => new StartEvent()]);

        $this->assertSame([' ', 'ramo-è'], array_keys($event->branches));
    }

    public function test_results_are_recorded_per_branch(): void
    {
        $event = new ParallelEvent(['left' => new StartEvent(), 'right' => new StartEvent()]);

        $this->assertSame([], $event->getAllResults());
        $this->assertFalse($event->hasResult('left'));

        $this->assertSame($event, $event->setResult('left', ['score' => 1]));
        $event->setResult('right', 'done');
        $event->setResult('left', ['score' => 2]);

        $this->assertTrue($event->hasResult('left'));
        $this->assertSame(['score' => 2], $event->getResult('left'));
        $this->assertSame(['left' => ['score' => 2], 'right' => 'done'], $event->getAllResults());
    }
}
