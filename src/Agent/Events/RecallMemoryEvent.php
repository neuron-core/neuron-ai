<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Workflow\Events\Event;

/** Routes the request through memory recall before inference. */
class RecallMemoryEvent implements Event
{
}
