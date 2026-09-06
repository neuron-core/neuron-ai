<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum ContentBlockType: string
{
    case TEXT = 'text';
    case REASONING = 'reasoning';
    case REDACTED_REASONING = 'redacted_reasoning';
    case IMAGE = 'image';
    case FILE = 'file';
    case AUDIO = 'audio';
    case VIDEO = 'video';
}
