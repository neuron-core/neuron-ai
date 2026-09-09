<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use RuntimeException;

class RestorableStateNode extends Node
{
    public function __construct(protected bool $fail = false, protected bool $suspend = false)
    {
    }

    public function __invoke(SecondTextProcessEvent $event, RestorableState $state): StopEvent
    {
        if ($this->fail) {
            throw new RuntimeException('Probe failed.');
        }

        if ($this->suspend) {
            $state->set('paused', true);
            $this->awaitEvent($state->get('__branchId', 'main'));
        }

        return new StopEvent(($state->operation)() . ':' . $state->get('branch_value'));
    }
}
