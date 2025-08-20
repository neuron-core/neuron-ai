<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetWeekNumberTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_week_number',
            description: 'Get the ISO week number for a given date',
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
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
        ];
    }

    public function __invoke(string $date, ?string $timezone = null): string
    {
        $timezone ??= 'UTC';

        try {
            $tz = new DateTimeZone($timezone);

            $dateTime = \is_numeric($date)
                ? (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz)
                : new \DateTime($date, $tz);

            $weekNumber = (int) $dateTime->format('W');
            $year = (int) $dateTime->format('o');
            $dayOfWeek = (int) $dateTime->format('N');

            return \json_encode([
                'week_number' => $weekNumber,
                'iso_year' => $year,
                'day_of_week' => $dayOfWeek,
                'week_start' => $dateTime->modify('monday this week')->format('Y-m-d'),
                'week_end' => $dateTime->modify('sunday this week')->format('Y-m-d'),
                'formatted' => "{$year}-W" . \str_pad((string) $weekNumber, 2, '0', \STR_PAD_LEFT),
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
