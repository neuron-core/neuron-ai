<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class DuplicateCallIdWeatherTool extends Tool
{
    /** @var string[] */
    public static array $executions = [];

    protected string $name = 'get_weather';

    protected ?string $description = 'Get the weather for a city.';

    protected function properties(): array
    {
        return [
            new ToolProperty('city', PropertyType::STRING, 'The city', true),
        ];
    }

    public function __invoke(string $city): string
    {
        self::$executions[] = $city;
        return "Weather in {$city}: sunny";
    }
}
