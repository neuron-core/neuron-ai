<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Resume;

enum ResumeKind: string
{
    case Event = 'event';
    case Expired = 'expired';
    case Timer = 'timer';
}
