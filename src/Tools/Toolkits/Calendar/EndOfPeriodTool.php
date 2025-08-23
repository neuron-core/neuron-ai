<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class EndOfPeriodTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'end_of_period',
            description: 'Get the end of week, month, quarter, or year for a given date',
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
                name: 'period',
                type: PropertyType::STRING,
                description: 'Period type: "week", "month", "quarter", "year"',
                required: true,
                enum: ['week', 'month', 'quarter', 'year'],
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
            ToolProperty::make(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Output format. Defaults to "Y-m-d H:i:s".',
            ),
        ];
    }

    public function __invoke(string $date, string $period, ?string $timezone = null, ?string $format = null): string
    {
        $timezone ??= 'UTC';
        $format ??= 'Y-m-d H:i:s';

        try {
            $tz = new DateTimeZone($timezone);

            $dateTime = \is_numeric($date)
                ? (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz)
                : new \DateTime($date, $tz);

            match ($period) {
                'week' => $dateTime->modify('sunday this week')->setTime(23, 59, 59),
                'month' => $dateTime->modify('last day of this month')->setTime(23, 59, 59),
                'quarter' => $this->setEndOfQuarter($dateTime),
                'year' => $dateTime->setDate((int) $dateTime->format('Y'), 12, 31)->setTime(23, 59, 59),
                default => throw new \InvalidArgumentException("Unsupported period: {$period}"),
            };

            return $dateTime->format($format);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    private function setEndOfQuarter(\DateTime $dateTime): void
    {
        $month = (int) $dateTime->format('n');
        $quarterEndMonth = match (true) {
            $month <= 3 => 3,
            $month <= 6 => 6,
            $month <= 9 => 9,
            default => 12,
        };

        $dateTime->setDate((int) $dateTime->format('Y'), $quarterEndMonth, 1)
                 ->modify('last day of this month')
                 ->setTime(23, 59, 59);
    }
}
