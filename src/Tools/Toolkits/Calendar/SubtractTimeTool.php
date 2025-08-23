<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SubtractTimeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'subtract_time',
            description: 'Subtract time periods from a date (supports days, weeks, months, years, hours, minutes, seconds)',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Base date string or timestamp',
                required: true,
            ),
            ToolProperty::make(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Amount to subtract (positive number)',
                required: true,
            ),
            ToolProperty::make(
                name: 'unit',
                type: PropertyType::STRING,
                description: 'Time unit: "seconds", "minutes", "hours", "days", "weeks", "months", "years"',
                required: true,
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

    public function __invoke(string $date, int|float $amount, string $unit, ?string $timezone = null, ?string $format = null): string
    {
        $timezone ??= 'UTC';
        $format ??= 'Y-m-d H:i:s';

        try {
            $tz = new DateTimeZone($timezone);

            $dateTime = \is_numeric($date)
                ? (new \DateTime())->setTimestamp((int) $date)->setTimezone($tz)
                : new \DateTime($date, $tz);

            $intervalSpec = match ($unit) {
                'seconds' => "PT" . (int) $amount . "S",
                'minutes' => "PT" . (int) $amount . "M",
                'hours' => "PT" . (int) $amount . "H",
                'days' => "P" . (int) $amount . "D",
                'weeks' => "P" . ((int) $amount * 7) . "D",
                'months' => "P" . (int) $amount . "M",
                'years' => "P" . (int) $amount . "Y",
                default => throw new \InvalidArgumentException("Unsupported unit: {$unit}"),
            };

            // Handle fractional amounts by subtracting additional smaller units
            $fracPart = $amount - (int) $amount;
            if ($fracPart > 0) {
                $additionalSeconds = match ($unit) {
                    'minutes' => (int) ($fracPart * 60),
                    'hours' => (int) ($fracPart * 3600),
                    'days' => (int) ($fracPart * 86400),
                    default => 0,
                };

                if ($additionalSeconds > 0) {
                    $interval = new \DateInterval($intervalSpec);
                    $dateTime->sub($interval);
                    $interval = new \DateInterval("PT{$additionalSeconds}S");
                    $dateTime->sub($interval);
                    return $dateTime->format($format);
                }
            }

            $interval = new \DateInterval($intervalSpec);
            $dateTime->sub($interval);

            return $dateTime->format($format);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
