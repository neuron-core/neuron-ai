<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetDaysInMonthTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class GetDaysInMonthToolTest extends TestCase
{
    protected GetDaysInMonthTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetDaysInMonthTool();
    }

    public function test_describes_the_month(): void
    {
        $this->assertSame([
            'month' => 1,
            'month_name' => 'January',
            'year' => 2023,
            'days_in_month' => 31,
            'is_leap_year' => false,
            'first_day' => '2023-01-01',
            'last_day' => '2023-01-31',
        ], json_decode(($this->tool)(1, 2023), true));
    }

    /**
     * @return array<string, array{int, string, int, int}>
     */
    public static function months(): array
    {
        $cases = [];
        foreach ([2023 => 28, 2024 => 29] as $year => $februaryDays) {
            foreach ([
                1 => ['January', 31], 2 => ['February', $februaryDays], 3 => ['March', 31], 4 => ['April', 30],
                5 => ['May', 31], 6 => ['June', 30], 7 => ['July', 31], 8 => ['August', 31],
                9 => ['September', 30], 10 => ['October', 31], 11 => ['November', 30], 12 => ['December', 31],
            ] as $month => [$name, $days]) {
                $cases["{$name} {$year}"] = [$month, $name, $year, $days];
            }
        }

        return $cases;
    }

    #[DataProvider('months')]
    public function test_returns_the_length_and_bounds_of_every_month(int $month, string $name, int $year, int $days): void
    {
        $result = json_decode(($this->tool)($month, $year), true);

        $paddedMonth = $month < 10 ? "0{$month}" : (string) $month;
        $this->assertSame($name, $result['month_name']);
        $this->assertSame($days, $result['days_in_month']);
        $this->assertSame($year === 2024, $result['is_leap_year']);
        $this->assertSame("{$year}-{$paddedMonth}-01", $result['first_day']);
        $this->assertSame("{$year}-{$paddedMonth}-{$days}", $result['last_day']);
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function centuryFebruaries(): array
    {
        return [
            '1900 is not a leap year' => [1900, 28, false],
            '2000 is a leap year' => [2000, 29, true],
            '2100 is not a leap year' => [2100, 28, false],
            '1800 is not a leap year although divisible by 200' => [1800, 28, false],
            '2400 is a leap year' => [2400, 29, true],
            '1776 is a leap year' => [1776, 29, true],
        ];
    }

    #[DataProvider('centuryFebruaries')]
    public function test_applies_the_gregorian_century_rule(int $year, int $days, bool $leap): void
    {
        $result = json_decode(($this->tool)(2, $year), true);

        $this->assertSame([$days, $leap], [$result['days_in_month'], $result['is_leap_year']]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidMonths(): array
    {
        return [
            'zero' => [0],
            'thirteen' => [13],
            'negative' => [-1],
        ];
    }

    #[DataProvider('invalidMonths')]
    public function test_a_month_outside_one_to_twelve_is_rejected(int $month): void
    {
        $this->assertSame('Error: Month must be between 1 and 12', ($this->tool)($month, 2023));
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('get_days_in_month', $this->tool->getName());
        $this->assertSame('Get the number of days in a specific month and year', $this->tool->getDescription());
        $this->assertSame(['month', 'year'], array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties()));
        $this->assertSame(['month', 'year'], $this->tool->getRequiredProperties());
    }
}
