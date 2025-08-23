<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class DateDifferenceTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'date_difference',
            description: 'Calculate the difference between two dates in various units',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'start_date',
                type: PropertyType::STRING,
                description: 'Start date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'end_date',
                type: PropertyType::STRING,
                description: 'End date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'unit',
                type: PropertyType::STRING,
                description: 'Unit for difference: "seconds", "minutes", "hours", "days", "weeks", "months", "years", or "all" for detailed breakdown',
                enum: ['seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years', 'all'],
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
        ];
    }

    public function __invoke(string $start_date, string $end_date, ?string $unit = null, ?string $timezone = null): string
    {
        $unit ??= 'days';
        $timezone ??= 'UTC';

        try {
            $tz = new DateTimeZone($timezone);

            $start = \is_numeric($start_date)
                ? (new \DateTime())->setTimestamp((int) $start_date)->setTimezone($tz)
                : new \DateTime($start_date, $tz);

            $end = \is_numeric($end_date)
                ? (new \DateTime())->setTimestamp((int) $end_date)->setTimezone($tz)
                : new \DateTime($end_date, $tz);

            $interval = $start->diff($end);
            $totalSeconds = $end->getTimestamp() - $start->getTimestamp();

            return match ($unit) {
                'seconds' => (string) \abs($totalSeconds),
                'minutes' => (string) \abs(\round($totalSeconds / 60, 2)),
                'hours' => (string) \abs(\round($totalSeconds / 3600, 2)),
                'days' => (string) \abs($interval->days),
                'weeks' => (string) \abs(\round($interval->days / 7, 2)),
                'months' => (string) \abs($interval->y * 12 + $interval->m),
                'years' => (string) \abs($interval->y),
                'all' => \json_encode([
                    'years' => $interval->y,
                    'months' => $interval->m,
                    'days' => $interval->d,
                    'hours' => $interval->h,
                    'minutes' => $interval->i,
                    'seconds' => $interval->s,
                    'total_days' => $interval->days,
                    'total_seconds' => \abs($totalSeconds),
                ]),
                default => (string) \abs($interval->days),
            };
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
