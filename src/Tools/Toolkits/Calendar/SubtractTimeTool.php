<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateInterval;
use DateTime;

class SubtractTimeTool extends TimeShiftTool
{
    protected string $name = 'subtract_time';

    protected ?string $description = 'Subtract time periods from a date (supports days, weeks, months, years, hours, minutes, seconds)';

    protected string $amountDescription = 'Amount to subtract (positive number)';

    protected function shift(DateTime $dateTime, DateInterval $interval): void
    {
        $dateTime->sub($interval);
    }
}
