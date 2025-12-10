<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum AttachmentContentType: string
{
    case URL = 'url';
    case BASE64 = 'base64';
    case ID = 'id';
}
