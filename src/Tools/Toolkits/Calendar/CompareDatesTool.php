<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CompareDatesTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'compare_dates',
            description: 'Compare two dates and determine their relationship (before, after, equal)',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date1',
                type: PropertyType::STRING,
                description: 'First date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'date2',
                type: PropertyType::STRING,
                description: 'Second date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
                required: false,
            ),
            ToolProperty::make(
                name: 'precision',
                type: PropertyType::STRING,
                description: 'Comparison precision: "second", "minute", "hour", "day", "month", "year"',
                enum: ['second', 'minute', 'hour', 'day', 'month', 'year'],
            ),
        ];
    }

    public function __invoke(string $date1, string $date2, ?string $timezone = null, ?string $precision = null): string
    {
        $timezone ??= 'UTC';
        $precision ??= 'second';

        try {
            $tz = new DateTimeZone($timezone);

            $dateTime1 = \is_numeric($date1)
                ? (new \DateTime())->setTimestamp((int) $date1)->setTimezone($tz)
                : new \DateTime($date1, $tz);

            $dateTime2 = \is_numeric($date2)
                ? (new \DateTime())->setTimestamp((int) $date2)->setTimezone($tz)
                : new \DateTime($date2, $tz);

            $this->normalizeDateTimes($dateTime1, $dateTime2, $precision);

            $comparison = $dateTime1 <=> $dateTime2;

            return \json_encode([
                'date1' => $dateTime1->format('Y-m-d H:i:s'),
                'date2' => $dateTime2->format('Y-m-d H:i:s'),
                'comparison' => match ($comparison) {
                    -1 => 'before',
                    0 => 'equal',
                    1 => 'after',
                },
                'is_before' => $comparison < 0,
                'is_after' => $comparison > 0,
                'is_equal' => $comparison === 0,
                'precision' => $precision,
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    private function normalizeDateTimes(\DateTime $dateTime1, \DateTime $dateTime2, string $precision): void
    {
        match ($precision) {
            'year' => [
                $dateTime1->setDate((int) $dateTime1->format('Y'), 1, 1)->setTime(0, 0, 0),
                $dateTime2->setDate((int) $dateTime2->format('Y'), 1, 1)->setTime(0, 0, 0),
            ],
            'month' => [
                $dateTime1->setDate((int) $dateTime1->format('Y'), (int) $dateTime1->format('n'), 1)->setTime(0, 0, 0),
                $dateTime2->setDate((int) $dateTime2->format('Y'), (int) $dateTime2->format('n'), 1)->setTime(0, 0, 0),
            ],
            'day' => [
                $dateTime1->setTime(0, 0, 0),
                $dateTime2->setTime(0, 0, 0),
            ],
            'hour' => [
                $dateTime1->setTime((int) $dateTime1->format('H'), 0, 0),
                $dateTime2->setTime((int) $dateTime2->format('H'), 0, 0),
            ],
            'minute' => [
                $dateTime1->setTime((int) $dateTime1->format('H'), (int) $dateTime1->format('i'), 0),
                $dateTime2->setTime((int) $dateTime2->format('H'), (int) $dateTime2->format('i'), 0),
            ],
            default => null,
        };
    }
}
