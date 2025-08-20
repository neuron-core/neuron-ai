<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetWeekdayTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_weekday',
            description: 'Get the day of week name and number for a given date',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Return format: "name" (Monday), "short" (Mon), "number" (1-7), or "all" for detailed info',
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
        ];
    }

    public function __invoke(string $date, ?string $format = null, ?string $timezone = null): string
    {
        $format ??= 'name';
        $timezone ??= 'UTC';

        try {
            $tz = new DateTimeZone($timezone);

            if (\is_numeric($date)) {
                $dateTime = (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz);
            } else {
                // First create the DateTime object, then convert to the target timezone
                $dateTime = new \DateTime($date);
                $dateTime->setTimezone($tz);
            }

            return match ($format) {
                'name' => $dateTime->format('l'),
                'short' => $dateTime->format('D'),
                'number' => $dateTime->format('N'),
                'all' => \json_encode([
                    'name' => $dateTime->format('l'),
                    'short' => $dateTime->format('D'),
                    'number' => (int) $dateTime->format('N'),
                    'iso_number' => (int) $dateTime->format('N'),
                    'us_number' => (int) $dateTime->format('w'),
                ]),
                default => $dateTime->format('l'),
            };
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
