<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\IsLeapYearTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class IsLeapYearToolTest extends TestCase
{
    protected IsLeapYearTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsLeapYearTool();
    }

    public function test_describes_a_leap_year(): void
    {
        $this->assertSame(
            ['year' => 2024, 'is_leap_year' => true, 'days_in_year' => 366, 'february_days' => 29],
            json_decode(($this->tool)(2024), true)
        );
    }

    public function test_describes_a_common_year(): void
    {
        $this->assertSame(
            ['year' => 2023, 'is_leap_year' => false, 'days_in_year' => 365, 'february_days' => 28],
            json_decode(($this->tool)(2023), true)
        );
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function years(): array
    {
        return [
            'divisible by 4' => [1996, true],
            'divisible by 4 in the future' => [2028, true],
            'not divisible by 4' => [2021, false],
            'two after a leap year' => [2022, false],
            'three after a leap year' => [2019, false],
            'century not divisible by 400 (1700)' => [1700, false],
            'century not divisible by 400 (1800)' => [1800, false],
            'century not divisible by 400 (1900)' => [1900, false],
            'century not divisible by 400 (2100)' => [2100, false],
            'century not divisible by 400 (2200)' => [2200, false],
            'century divisible by 400 (1600)' => [1600, true],
            'century divisible by 400 (2000)' => [2000, true],
            'century divisible by 400 (2400)' => [2400, true],
        ];
    }

    #[DataProvider('years')]
    public function test_applies_the_gregorian_leap_year_rule(int $year, bool $leap): void
    {
        $result = json_decode(($this->tool)($year), true);

        $this->assertSame(
            [$year, $leap, $leap ? 366 : 365, $leap ? 29 : 28],
            [$result['year'], $result['is_leap_year'], $result['days_in_year'], $result['february_days']]
        );
    }

    public function test_a_year_sent_as_a_string_is_cast_before_invocation(): void
    {
        $this->tool->setInputs(['year' => '2000'])->execute();

        $this->assertSame('{"year":2000,"is_leap_year":true,"days_in_year":366,"february_days":29}', $this->tool->getResult());
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('is_leap_year', $this->tool->getName());
        $this->assertSame('Check if a given year is a leap year', $this->tool->getDescription());
        $this->assertSame(['year'], array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties()));
        $this->assertSame(['year'], $this->tool->getRequiredProperties());
    }
}
