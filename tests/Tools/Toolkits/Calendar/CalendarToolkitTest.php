<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\CalculateAgeTool;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CompareDatesTool;
use NeuronAI\Tools\Toolkits\Calendar\ConvertTimezoneTool;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\DateDifferenceTool;
use NeuronAI\Tools\Toolkits\Calendar\EndOfPeriodTool;
use NeuronAI\Tools\Toolkits\Calendar\FormatDateTool;
use NeuronAI\Tools\Toolkits\Calendar\GetDaysInMonthTool;
use NeuronAI\Tools\Toolkits\Calendar\GetTimestampTool;
use NeuronAI\Tools\Toolkits\Calendar\GetTimezoneInfoTool;
use NeuronAI\Tools\Toolkits\Calendar\GetWeekdayTool;
use NeuronAI\Tools\Toolkits\Calendar\GetWeekNumberTool;
use NeuronAI\Tools\Toolkits\Calendar\IsDateInRangeTool;
use NeuronAI\Tools\Toolkits\Calendar\IsLeapYearTool;
use NeuronAI\Tools\Toolkits\Calendar\IsWeekendTool;
use NeuronAI\Tools\Toolkits\Calendar\StartOfPeriodTool;
use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;

class CalendarToolkitTest extends TestCase
{
    public function test_provides_every_calendar_tool_under_a_unique_name(): void
    {
        $tools = CalendarToolkit::make()->tools();
        $names = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);

        $this->assertSame([
            CurrentDateTimeTool::class,
            GetTimestampTool::class,
            FormatDateTool::class,
            DateDifferenceTool::class,
            AddTimeTool::class,
            SubtractTimeTool::class,
            CalculateAgeTool::class,
            ConvertTimezoneTool::class,
            GetTimezoneInfoTool::class,
            GetWeekdayTool::class,
            IsWeekendTool::class,
            IsLeapYearTool::class,
            GetDaysInMonthTool::class,
            StartOfPeriodTool::class,
            EndOfPeriodTool::class,
            GetWeekNumberTool::class,
            CompareDatesTool::class,
            IsDateInRangeTool::class,
        ], array_map(static fn (ToolInterface $tool): string => $tool::class, $tools));
        $this->assertSame($names, array_unique($names));
    }

    /**
     * Only the required inputs are sent, as a model does when it relies on the documented defaults.
     *
     * @return array<string, array{ToolInterface, array<string, mixed>, string}>
     */
    public static function requiredInputsOnly(): array
    {
        return [
            'add_time' => [new AddTimeTool(), ['date' => '2023-01-01', 'amount' => 1, 'unit' => 'days'], '2023-01-02 00:00:00'],
            'subtract_time' => [new SubtractTimeTool(), ['date' => '2023-01-02', 'amount' => 1, 'unit' => 'days'], '2023-01-01 00:00:00'],
            'calculate_age' => [new CalculateAgeTool(), ['birthdate' => '1990-01-01', 'reference_date' => '2023-01-01'], '33'],
            'compare_dates' => [new CompareDatesTool(), ['date1' => '2023-01-01', 'date2' => '2023-01-01'], '{"date1":"2023-01-01 00:00:00","date2":"2023-01-01 00:00:00","comparison":"equal","is_before":false,"is_after":false,"is_equal":true,"precision":"second"}'],
            'convert_timezone' => [new ConvertTimezoneTool(), ['date' => '2023-01-01 12:00', 'from_timezone' => 'UTC', 'to_timezone' => 'Asia/Tokyo'], '2023-01-01 21:00:00 JST'],
            'date_difference' => [new DateDifferenceTool(), ['start_date' => '2023-01-01', 'end_date' => '2023-01-31'], '30'],
            'end_of_period' => [new EndOfPeriodTool(), ['date' => '2023-02-10', 'period' => 'month'], '2023-02-28 23:59:59'],
            'format_date' => [new FormatDateTool(), ['date' => '2023-01-01 08:30'], '2023-01-01 08:30:00'],
            'get_days_in_month' => [new GetDaysInMonthTool(), ['month' => 2, 'year' => 2023], '{"month":2,"month_name":"February","year":2023,"days_in_month":28,"is_leap_year":false,"first_day":"2023-02-01","last_day":"2023-02-28"}'],
            'get_timestamp' => [new GetTimestampTool(), ['date' => '2023-01-01 00:00:00'], '1672531200'],
            'get_timezone_info' => [new GetTimezoneInfoTool(), ['timezone' => 'UTC', 'reference_date' => '2023-01-01'], '{"timezone":"UTC","offset_seconds":0,"offset_hours":0,"offset_formatted":"+00:00","is_dst":false,"abbreviation":"UTC","location":null,"reference_time":"2023-01-01 00:00:00 UTC"}'],
            'get_week_number' => [new GetWeekNumberTool(), ['date' => '2023-01-01'], '{"week_number":52,"iso_year":2022,"day_of_week":7,"week_start":"2022-12-26","week_end":"2023-01-01","formatted":"2022-W52"}'],
            'get_weekday' => [new GetWeekdayTool(), ['date' => '2023-01-01'], 'Sunday'],
            'is_date_in_range' => [new IsDateInRangeTool(), ['date' => '2023-01-02', 'start_date' => '2023-01-01', 'end_date' => '2023-01-03'], '{"date":"2023-01-02 00:00:00","start_date":"2023-01-01 00:00:00","end_date":"2023-01-03 00:00:00","is_in_range":true,"is_before_range":false,"is_after_range":false,"days_from_start":1,"precision":"second"}'],
            'is_leap_year' => [new IsLeapYearTool(), ['year' => 2024], '{"year":2024,"is_leap_year":true,"days_in_year":366,"february_days":29}'],
            'is_weekend' => [new IsWeekendTool(), ['date' => '2023-01-01'], '{"is_weekend":true,"day_of_week":"Sunday","day_number":7}'],
            'start_of_period' => [new StartOfPeriodTool(), ['date' => '2023-02-10', 'period' => 'quarter'], '2023-01-01 00:00:00'],
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     */
    #[DataProvider('requiredInputsOnly')]
    public function test_optional_inputs_fall_back_to_their_documented_defaults(ToolInterface $tool, array $inputs, string $expected): void
    {
        $tool->setInputs($inputs)->execute();

        $this->assertSame($expected, $tool->getResult());
    }

    public function test_the_current_datetime_tool_needs_no_input(): void
    {
        $tool = new CurrentDateTimeTool();

        $tool->setInputs([])->execute();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $tool->getResult());
    }

    public function test_numeric_inputs_sent_as_strings_are_cast_before_invocation(): void
    {
        $days = new GetDaysInMonthTool();
        $add = new AddTimeTool();

        $days->setInputs(['month' => '2', 'year' => '2024'])->execute();
        $add->setInputs(['date' => '2023-01-01 00:00:00', 'amount' => '1.5', 'unit' => 'hours'])->execute();

        $this->assertStringContainsString('"days_in_month":29', (string) $days->getResult());
        $this->assertSame('2023-01-01 01:30:00', $add->getResult());
    }

    public function test_a_non_numeric_amount_is_returned_to_the_model_without_invoking_the_tool(): void
    {
        $tool = new AddTimeTool();

        $tool->setInputs(['date' => '2023-01-01', 'amount' => 'three', 'unit' => 'days'])->execute();

        $this->assertSame('Parameter "amount" must be of type number, string given.', (string) $tool->getResult());
    }
}
