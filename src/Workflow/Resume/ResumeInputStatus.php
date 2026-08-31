<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Resume;

enum ResumeInputStatus: string
{
    case Accepted = 'accepted';
    case Stale = 'stale';
}
