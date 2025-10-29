<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum ContentBlockType: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case FILE = 'file';
    case AUDIO = 'audio';
    case VIDEO = 'video';
    case TOOL_USE = 'tool_use';
    case TOOL_RESULT = 'tool_result';
}
