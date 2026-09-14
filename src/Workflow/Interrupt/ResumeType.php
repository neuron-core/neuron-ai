<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/** @internal Input kinds are selected by the executor. */
enum ResumeType: string
{
    case Event = 'event';
    case Expired = 'expired';
    case Timer = 'timer';
}
