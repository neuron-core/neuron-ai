<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetTimezoneInfoTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class TimezoneOffsetFormattingTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function offsets(): array
    {
        return [
            'Newfoundland' => ['America/St_Johns', '2023-01-15 12:00:00', '-03:30'],
            'Marquesas' => ['Pacific/Marquesas', '2023-01-15 12:00:00', '-09:30'],
            'Monrovia before 1972' => ['Africa/Monrovia', '1970-06-01 12:00:00', '-00:44'],
            'India' => ['Asia/Kolkata', '2023-01-15 12:00:00', '+05:30'],
            'New York' => ['America/New_York', '2023-01-15 12:00:00', '-05:00'],
            'UTC' => ['UTC', '2023-01-15 12:00:00', '+00:00'],
        ];
    }

    #[DataProvider('offsets')]
    public function test_offsets_are_formatted_as_signed_hours_and_minutes(string $timezone, string $referenceDate, string $expected): void
    {
        $result = json_decode((new GetTimezoneInfoTool())($timezone, $referenceDate), true);

        $this->assertSame($expected, $result['offset_formatted']);
    }
}
