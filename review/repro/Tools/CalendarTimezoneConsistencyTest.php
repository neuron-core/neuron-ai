<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetWeekdayTool;
use NeuronAI\Tools\Toolkits\Calendar\GetWeekNumberTool;
use NeuronAI\Tools\Toolkits\Calendar\IsWeekendTool;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function json_decode;

class CalendarTimezoneConsistencyTest extends TestCase
{
    protected string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    public function test_is_weekend_interprets_the_date_string_in_the_requested_timezone(): void
    {
        // Friday 23:00 local Tokyo time is not a weekend
        $result = json_decode((new IsWeekendTool())('2023-06-16 23:00:00', 'Asia/Tokyo'), true);

        $this->assertSame('Friday', $result['day_of_week']);
        $this->assertFalse($result['is_weekend']);
    }

    public function test_get_weekday_interprets_the_date_string_in_the_requested_timezone(): void
    {
        // Friday 23:00 in Tokyo, independent of PHP's default timezone
        $this->assertSame('Friday', (new GetWeekdayTool())('2023-06-16 23:00:00', 'name', 'Asia/Tokyo'));
    }

    public function test_day_based_tools_agree_on_the_day_of_the_same_input(): void
    {
        $weekNumber = json_decode((new GetWeekNumberTool())('2023-06-16 23:00:00', 'Asia/Tokyo'), true);
        $weekend = json_decode((new IsWeekendTool())('2023-06-16 23:00:00', 'Asia/Tokyo'), true);
        $weekday = (new GetWeekdayTool())('2023-06-16 23:00:00', 'number', 'Asia/Tokyo');

        $this->assertSame(5, $weekNumber['day_of_week']);
        $this->assertSame($weekNumber['day_of_week'], $weekend['day_number']);
        $this->assertSame((string) $weekNumber['day_of_week'], $weekday);
    }
}
