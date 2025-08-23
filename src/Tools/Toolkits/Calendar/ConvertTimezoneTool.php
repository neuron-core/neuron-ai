<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ConvertTimezoneTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'convert_timezone',
            description: 'Convert a date/time from one timezone to another',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Date string or timestamp to convert',
                required: true,
            ),
            ToolProperty::make(
                name: 'from_timezone',
                type: PropertyType::STRING,
                description: 'Source timezone identifier',
                required: true,
            ),
            ToolProperty::make(
                name: 'to_timezone',
                type: PropertyType::STRING,
                description: 'Target timezone identifier',
                required: true,
            ),
            ToolProperty::make(
                name: 'format',
                type: PropertyType::STRING,
                description: 'Output format. Defaults to "Y-m-d H:i:s T".',
            ),
        ];
    }

    public function __invoke(string $date, string $from_timezone, string $to_timezone, ?string $format = null): string
    {
        $format ??= 'Y-m-d H:i:s T';

        try {
            $fromTz = new DateTimeZone($from_timezone);
            $toTz = new DateTimeZone($to_timezone);

            $dateTime = \is_numeric($date)
                ? (new \DateTime())->setTimestamp((int) $date)->setTimezone($fromTz)
                : new \DateTime($date, $fromTz);

            $dateTime->setTimezone($toTz);

            return $dateTime->format($format);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
