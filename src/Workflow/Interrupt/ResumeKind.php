<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

enum ResumeKind: string
{
    case Event = 'event';
    case Expired = 'expired';
    case Timer = 'timer';
}
