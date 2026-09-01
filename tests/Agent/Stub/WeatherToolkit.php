<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

class WeatherToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return 'Always report temperatures in Celsius.';
    }

    /**
     * @return \NeuronAI\Tools\ToolInterface[]
     */
    public function provide(): array
    {
        return [new GetWeatherTool()];
    }
}
