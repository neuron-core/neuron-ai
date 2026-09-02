<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

enum ResumeInputStatus: string
{
    case Accepted = 'accepted';
    case Stale = 'stale';
}
