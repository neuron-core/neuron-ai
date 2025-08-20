<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class FormatDateTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'format_date',
            description: 'Format a date string or timestamp into different representations',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Date string or Unix timestamp to format',
                required: true,
            ),
            ToolProperty::make(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Output format string (PHP date format). Defaults to "Y-m-d H:i:s".',
            ),
            ToolProperty::make(
                name: 'input_timezone',
                type: PropertyType::STRING,
                description: 'Timezone of input date. Defaults to UTC.',
            ),
            ToolProperty::make(
                name: 'output_timezone',
                type: PropertyType::STRING,
                description: 'Timezone for output. Defaults to same as input timezone.',
            ),
        ];
    }

    public function __invoke(string $date, ?string $format = null, ?string $input_timezone = null, ?string $output_timezone = null): string
    {
        $format ??= 'Y-m-d H:i:s';
        $input_timezone ??= 'UTC';
        $output_timezone ??= $input_timezone;

        try {
            if (\is_numeric($date)) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp((int) $date);
                $dateTime->setTimezone(new DateTimeZone($input_timezone));
            } else {
                $dateTime = new \DateTime($date, new DateTimeZone($input_timezone));
            }

            if ($output_timezone !== $input_timezone) {
                $dateTime->setTimezone(new DateTimeZone($output_timezone));
            }

            return $dateTime->format($format);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
