<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum SourceType: string
{
    case URL = 'url';
    case BASE64 = 'base64';
}
