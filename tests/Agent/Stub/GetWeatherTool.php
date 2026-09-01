<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetWeatherTool extends Tool
{
    protected string $name = 'get_weather';

    protected ?string $description = 'Get current weather for a location';

    protected function properties(): array
    {
        return [
            new ToolProperty('location', PropertyType::STRING, 'Location', true),
        ];
    }

    public function __invoke(string $location): string
    {
        return "Weather for {$location}: sunny";
    }
}
