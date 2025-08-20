<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class IsDateInRangeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'is_date_in_range',
            description: 'Check if a date falls within a specified date range (inclusive)',
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
                name: 'start_date',
                type: PropertyType::STRING,
                description: 'Range start date string or timestamp (inclusive)',
                required: true,
            ),
            ToolProperty::make(
                name: 'end_date',
                type: PropertyType::STRING,
                description: 'Range end date string or timestamp (inclusive)',
                required: true,
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
            ToolProperty::make(
                name: 'precision',
                type: PropertyType::STRING,
                description: 'Comparison precision: "second", "minute", "hour", "day", "month", "year"',
                enum: ['second', 'minute', 'hour', 'day', 'month', 'year'],
            ),
        ];
    }

    public function __invoke(string $date, string $start_date, string $end_date, ?string $timezone = null, ?string $precision = null): string
    {
        $timezone ??= 'UTC';
        $precision ??= 'second';

        try {
            $tz = new DateTimeZone($timezone);

            $checkDate = \is_numeric($date)
                ? (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz)
                : new \DateTime($date, $tz);

            $startDate = \is_numeric($start_date)
                ? (new \DateTime())->setTimestamp((int) $start_date)->setTimezone($tz)
                : new \DateTime($start_date, $tz);

            $endDate = \is_numeric($end_date)
                ? (new \DateTime())->setTimestamp((int) $end_date)->setTimezone($tz)
                : new \DateTime($end_date, $tz);

            $this->normalizeDateTimes($checkDate, $startDate, $endDate, $precision);

            $isInRange = $checkDate >= $startDate && $checkDate <= $endDate;
            $daysDifference = $checkDate->diff($startDate)->days * ($checkDate >= $startDate ? 1 : -1);

            return \json_encode([
                'date' => $checkDate->format('Y-m-d H:i:s'),
                'start_date' => $startDate->format('Y-m-d H:i:s'),
                'end_date' => $endDate->format('Y-m-d H:i:s'),
                'is_in_range' => $isInRange,
                'is_before_range' => $checkDate < $startDate,
                'is_after_range' => $checkDate > $endDate,
                'days_from_start' => $daysDifference,
                'precision' => $precision,
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    private function normalizeDateTimes(\DateTime $checkDate, \DateTime $startDate, \DateTime $endDate, string $precision): void
    {
        match ($precision) {
            'year' => [
                $checkDate->setDate((int) $checkDate->format('Y'), 1, 1)->setTime(0, 0, 0),
                $startDate->setDate((int) $startDate->format('Y'), 1, 1)->setTime(0, 0, 0),
                $endDate->setDate((int) $endDate->format('Y'), 12, 31)->setTime(23, 59, 59),
            ],
            'month' => [
                $checkDate->setDate((int) $checkDate->format('Y'), (int) $checkDate->format('n'), 1)->setTime(0, 0, 0),
                $startDate->setDate((int) $startDate->format('Y'), (int) $startDate->format('n'), 1)->setTime(0, 0, 0),
                $endDate->setDate((int) $endDate->format('Y'), (int) $endDate->format('n'), 1)->modify('last day of this month')->setTime(23, 59, 59),
            ],
            'day' => [
                $checkDate->setTime(0, 0, 0),
                $startDate->setTime(0, 0, 0),
                $endDate->setTime(23, 59, 59),
            ],
            'hour' => [
                $checkDate->setTime((int) $checkDate->format('H'), 0, 0),
                $startDate->setTime((int) $startDate->format('H'), 0, 0),
                $endDate->setTime((int) $endDate->format('H'), 59, 59),
            ],
            'minute' => [
                $checkDate->setTime((int) $checkDate->format('H'), (int) $checkDate->format('i'), 0),
                $startDate->setTime((int) $startDate->format('H'), (int) $startDate->format('i'), 0),
                $endDate->setTime((int) $endDate->format('H'), (int) $endDate->format('i'), 59),
            ],
            default => null,
        };
    }
}
