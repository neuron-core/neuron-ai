<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use DateTime;
use Exception;

use function is_numeric;
use function json_encode;
use function round;
use function str_contains;

class GetTimezoneInfoTool extends Tool
{
    protected string $name = 'get_timezone_info';

    protected ?string $description = 'Get detailed information about a timezone including offset and DST rules';

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
                $date = new DateTime('now', $tz);
            } else {
                $date = is_numeric($reference_date)
                    ? (new DateTime())->setTimestamp((int) $reference_date)->setTimezone($tz)
                    : new DateTime($reference_date, $tz);
            }

            $offset = $tz->getOffset($date);

            $transitions = $tz->getTransitions($date->getTimestamp(), $date->getTimestamp() + (365 * 24 * 3600));
            $isDst = !empty($transitions) && $transitions[0]['isdst'];

            $location = $tz->getLocation();

            return json_encode([
                'timezone' => $timezone,
                'offset_seconds' => $offset,
                'offset_hours' => $offset / 3600,
                'offset_formatted' => $date->format('P'),
                'is_dst' => $isDst,
                'abbreviation' => $date->format('T'),
                'location' => ($location !== false && !str_contains($location['country_code'], '?')) ? [
                    'country_code' => $location['country_code'],
                    // Arc-minute precision of the source data; also hides float noise that differs between timezone databases
                    'latitude' => round($location['latitude'], 4),
                    'longitude' => round($location['longitude'], 4),
                ] : null,
                'reference_time' => $date->format('Y-m-d H:i:s T'),
            ]);
        } catch (Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
