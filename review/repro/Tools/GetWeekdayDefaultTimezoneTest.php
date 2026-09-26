<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetWeekdayTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function date_default_timezone_set;

class GetWeekdayDefaultTimezoneTest extends TestCase
{
    protected string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function serverDefaultTimezones(): array
    {
        return [
            'server behind UTC, late evening' => ['America/New_York', '2024-01-15 23:00:00', 'Monday'],
            'server ahead of UTC, early morning' => ['Asia/Tokyo', '2024-01-15 02:00:00', 'Monday'],
        ];
    }

    #[DataProvider('serverDefaultTimezones')]
    public function test_the_result_does_not_depend_on_the_server_default_timezone(string $serverTimezone, string $date, string $weekday): void
    {
        date_default_timezone_set($serverTimezone);

        $this->assertSame($weekday, (new GetWeekdayTool())($date, 'name', 'UTC'));
    }
}
