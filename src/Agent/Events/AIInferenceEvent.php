<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Workflow\Events\Event;

class AIInferenceEvent implements Event
{
    public static function fromRequest(InferenceRequest $request): self
    {
        return $request->options->outputClass === null
            ? new self()
            : new StructuredInferenceEvent();
    }
}
