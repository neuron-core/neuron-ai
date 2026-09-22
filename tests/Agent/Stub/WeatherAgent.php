<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\Agent;

class WeatherAgent extends Agent
{
    protected function tools(\NeuronAI\Workflow\ExecutionContext $context): array
    {
        return [new WeatherToolkit()];
    }
}
