<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function is_numeric;
use function round;

/**
 * Base of the tools that move a date by an amount of time units. The whole
 * amount moves the calendar, so days keep the wall-clock time across a DST
 * change and months overflow like PHP's date arithmetic (Jan 31 + 1 month is
 * Mar 2 or 3); the fraction of a fixed-length unit moves it by seconds.
 */
abstract class TimeShiftTool extends Tool
{
    protected const UNITS = ['seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'];

    /**
     * The units with a fixed length, which alone can take a fractional amount.
     */
    protected const UNIT_SECONDS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800];

    protected string $amountDescription;

    abstract protected function shift(DateTime $dateTime, DateInterval $interval): void;

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
                description: $this->amountDescription,
                required: true,
            ),
            ToolProperty::make(
                name: 'unit',
                type: PropertyType::STRING,
                description: 'Time unit: "seconds", "minutes", "hours", "days", "weeks", "months", "years"',
                required: true,
                enum: self::UNITS,
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

            $dateTime = is_numeric($date)
                ? (new DateTime())->setTimestamp((int) $date)->setTimezone($tz)
                : new DateTime($date, $tz);

            if ($amount < 0) {
                throw new InvalidArgumentException('The amount must not be negative.');
            }

            $whole = (int) $amount;
            $fraction = $amount - $whole;
            $interval = new DateInterval($this->intervalSpec($whole, $unit));

            if ($fraction > 0 && !isset(self::UNIT_SECONDS[$unit])) {
                throw new InvalidArgumentException('Fractional amounts are only supported for seconds, minutes, hours, days and weeks.');
            }

            $this->shift($dateTime, $interval);

            // Rounded, not truncated: 2.3 minutes is 138 seconds, not 137.9999
            $seconds = (int) round($fraction * (self::UNIT_SECONDS[$unit] ?? 0));
            if ($seconds > 0) {
                $this->shift($dateTime, new DateInterval("PT{$seconds}S"));
            }

            return $dateTime->format($format);
        } catch (Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function intervalSpec(int $amount, string $unit): string
    {
        return match ($unit) {
            'seconds' => "PT{$amount}S",
            'minutes' => "PT{$amount}M",
            'hours' => "PT{$amount}H",
            'days' => "P{$amount}D",
            'weeks' => 'P' . ($amount * 7) . 'D',
            'months' => "P{$amount}M",
            'years' => "P{$amount}Y",
            default => throw new InvalidArgumentException("Unsupported unit: {$unit}"),
        };
    }
}
