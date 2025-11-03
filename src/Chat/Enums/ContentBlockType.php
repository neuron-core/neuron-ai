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
}
