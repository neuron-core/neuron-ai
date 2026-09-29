<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use DateInterval;
use DateTime;

class AddTimeTool extends TimeShiftTool
{
    protected string $name = 'add_time';

    protected ?string $description = 'Add time periods to a date (supports days, weeks, months, years, hours, minutes, seconds)';

    protected string $amountDescription = 'Amount to add (positive number)';

    protected function shift(DateTime $dateTime, DateInterval $interval): void
    {
        $dateTime->add($interval);
    }
}
