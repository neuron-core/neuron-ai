<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use stdClass;

use function Amp\delay;

class ConcurrentWaitNode extends Node
{
    public function __construct(
        protected stdClass $trace,
        protected bool $repeat = false,
        protected ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $branch = $state->get('__branchId');
        if (!$this->isResuming()) {
            $this->trace->events[] = "$branch.started";
            delay($branch === 'a' ? 0.001 : 0.01);
            $this->trace->events[] = "$branch.waiting";
        }
        $answer = $this->memoize('answer', fn (): ?array => $this->awaitEvent(
            $branch,
            $branch === 'b' ? $this->expiresAt : null,
        ));
        if ($branch === 'a' && $this->repeat) {
            $this->awaitEvent('a.again');
        }
        $this->trace->events[] = "$branch.finished";
        return new StopEvent($answer);
    }
}
