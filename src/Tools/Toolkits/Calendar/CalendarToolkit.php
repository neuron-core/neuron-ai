<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make()
 */
class CalendarToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "This toolkit provides comprehensive date and time operations. Use these tools to work with dates, times, formatting, calculations, and timezone conversions.";
    }

    public function provide(): array
    {
        return [
            CurrentDateTimeTool::make(),
            GetTimestampTool::make(),
            FormatDateTool::make(),
            DateDifferenceTool::make(),
            AddTimeTool::make(),
            SubtractTimeTool::make(),
            CalculateAgeTool::make(),
            ConvertTimezoneTool::make(),
            GetTimezoneInfoTool::make(),
            GetWeekdayTool::make(),
            IsWeekendTool::make(),
            IsLeapYearTool::make(),
            GetDaysInMonthTool::make(),
            StartOfPeriodTool::make(),
            EndOfPeriodTool::make(),
            GetWeekNumberTool::make(),
            CompareDatesTool::make(),
            IsDateInRangeTool::make(),
        ];
    }
}
