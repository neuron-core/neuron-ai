<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CurrentDateTimeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'current_datetime',
            description: 'Get the current date and time in the specified timezone and format',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone identifier (e.g., "UTC", "America/New_York", "Europe/London"). Defaults to UTC.',
            ),
            ToolProperty::make(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Date format string (PHP date format). Defaults to "Y-m-d H:i:s".',
            ),
        ];
    }

    public function __invoke(?string $timezone = null, ?string $format = null): string
    {
        $timezone ??= 'UTC';
        $format ??= 'Y-m-d H:i:s';

        try {
            $dateTime = new \DateTime('now', new DateTimeZone($timezone));
            return $dateTime->format($format);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
