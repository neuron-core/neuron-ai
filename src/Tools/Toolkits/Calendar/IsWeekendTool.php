<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class IsWeekendTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'is_weekend',
            description: 'Check if a given date falls on a weekend (Saturday or Sunday)',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Date string or timestamp to check',
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

            if (\is_numeric($date)) {
                // Handle timestamp
                $dateTime = (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz);
            } else {
                // Handle date string - always parse as UTC first, then convert to the target timezone
                $dateTime = new \DateTime($date, new DateTimeZone('UTC'));
                $dateTime->setTimezone($tz);
            }

            $dayOfWeek = (int) $dateTime->format('N');
            $isWeekend = $dayOfWeek >= 6;

            return \json_encode([
                'is_weekend' => $isWeekend,
                'day_of_week' => $dateTime->format('l'),
                'day_number' => $dayOfWeek,
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
