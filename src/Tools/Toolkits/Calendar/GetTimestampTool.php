<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetTimestampTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_timestamp',
            description: 'Get Unix timestamp for current time or convert a specific date to timestamp',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Date string to convert to timestamp. If null, returns current timestamp.',
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for the date. Defaults to UTC.',
            ),
        ];
    }

    public function __invoke(?string $date = null, ?string $timezone = null): string
    {
        $timezone ??= 'UTC';

        try {
            if ($date === null) {
                return (string) \time();
            }

            $dateTime = new \DateTime($date, new DateTimeZone($timezone));
            return (string) $dateTime->getTimestamp();
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
