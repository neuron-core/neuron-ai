<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CalculateAgeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'calculate_age',
            description: 'Calculate age in years, months, and days from birthdate to a reference date',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'birthdate',
                type: PropertyType::STRING,
                description: 'Birth date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'reference_date',
                type: PropertyType::STRING,
                description: 'Reference date for age calculation. Defaults to current date.',
                required: false,
            ),
            ToolProperty::make(
                name: 'unit',
                type: PropertyType::STRING,
                description: 'Return unit: "years", "months", "days", or "all" for detailed breakdown',
                enum: ['years', 'months', 'days', 'all'],
            ),
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone for date interpretation. Defaults to UTC.',
            ),
        ];
    }

    public function __invoke(string $birthdate, ?string $reference_date = null, ?string $unit = null, ?string $timezone = null): string
    {
        $unit ??= 'years';
        $timezone ??= 'UTC';

        try {
            $tz = new DateTimeZone($timezone);

            $birth = \is_numeric($birthdate)
                ? (new \DateTime())->setTimestamp((int) $birthdate)->setTimezone($tz)
                : new \DateTime($birthdate, $tz);

            if ($reference_date === null) {
                $reference = new \DateTime('now', $tz);
            } else {
                $reference = \is_numeric($reference_date)
                    ? (new \DateTime())->setTimestamp((int) $reference_date)->setTimezone($tz)
                    : new \DateTime($reference_date, $tz);
            }

            $interval = $birth->diff($reference);

            return match ($unit) {
                'years' => (string) $interval->y,
                'months' => (string) ($interval->y * 12 + $interval->m),
                'days' => (string) $interval->days,
                'all' => \json_encode([
                    'years' => $interval->y,
                    'months' => $interval->m,
                    'days' => $interval->d,
                    'total_days' => $interval->days,
                    'next_birthday' => $birth->modify('+' . ($interval->y + 1) . ' years')->format('Y-m-d'),
                ]),
                default => (string) $interval->y,
            };
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
