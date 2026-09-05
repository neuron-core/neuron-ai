<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class MemoizingWaitNode extends Node
{
    public function __construct(protected bool $fail = false)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $payload = $this->awaitEvent('answer', new DateTimeImmutable('@1'));
        $state->set('memo', $this->memoize('answer', fn (): ?array => $payload));

        if ($this->fail) {
            throw new RuntimeException('Failed after memoizing the accepted answer.');
        }

        $state->set('payload', $payload);

        return new StopEvent();
    }
}
