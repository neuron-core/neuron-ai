<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetTimezoneInfoTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_timezone_info',
            description: 'Get detailed information about a timezone including offset and DST rules',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'timezone',
                type: PropertyType::STRING,
                description: 'Timezone identifier to get information about',
                required: true,
            ),
            ToolProperty::make(
                name: 'reference_date',
                type: PropertyType::STRING,
                description: 'Reference date for timezone calculation. Defaults to current date.',
            ),
        ];
    }

    public function __invoke(string $timezone, ?string $reference_date = null): string
    {
        try {
            $tz = new DateTimeZone($timezone);

            if ($reference_date === null) {
                $date = new \DateTime('now', $tz);
            } else {
                $date = \is_numeric($reference_date)
                    ? (new \DateTime())->setTimestamp((int) $reference_date)->setTimezone($tz)
                    : new \DateTime($reference_date, $tz);
            }

            $offset = $tz->getOffset($date);
            $offsetHours = $offset / 3600;
            $offsetFormatted = \sprintf('%+03d:%02d', \floor($offsetHours), \abs($offset % 3600) / 60);

            $transitions = $tz->getTransitions($date->getTimestamp(), $date->getTimestamp() + (365 * 24 * 3600));
            $isDst = !empty($transitions) && $transitions[0]['isdst'];

            $location = $tz->getLocation();

            return \json_encode([
                'timezone' => $timezone,
                'offset_seconds' => $offset,
                'offset_hours' => $offsetHours,
                'offset_formatted' => $offsetFormatted,
                'is_dst' => $isDst,
                'abbreviation' => $date->format('T'),
                'location' => ($location !== false && !\str_contains($location['country_code'], '?')) ? [
                    'country_code' => $location['country_code'],
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                ] : null,
                'reference_time' => $date->format('Y-m-d H:i:s T'),
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
