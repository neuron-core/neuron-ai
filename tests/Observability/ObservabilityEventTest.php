<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Tests\Observability\Stub\StoppableTestEvent;
use PHPUnit\Framework\TestCase;
use stdClass;

class ObservabilityEventTest extends TestCase
{
    /**
     * The source is the emitting component (an agent holding provider credentials,
     * a node holding services): it must never leak into log context by default.
     */
    public function test_dispatch_stamps_are_not_part_of_the_event_data(): void
    {
        $source = new stdClass();
        $source->apiKey = 'sk-secret';
        $event = new StoppableTestEvent();
        $event->source = $source;
        $event->branchId = 'branch';

        $this->assertSame([], $event->toArray());
    }
}
