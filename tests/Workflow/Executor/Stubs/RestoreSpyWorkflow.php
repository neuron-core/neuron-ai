<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

class RestoreSpyWorkflow extends Workflow
{
    /** @var class-string[] */
    public array $restored = [];

    public function restoreEvent(Event $event): Event
    {
        $this->restored[] = $event::class;

        return parent::restoreEvent($event);
    }
}
