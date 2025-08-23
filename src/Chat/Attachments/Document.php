<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Attachments;

use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;

class Document extends Attachment
{
    public function __construct(
        string $document,
        AttachmentContentType $type = AttachmentContentType::URL,
        ?string $mediaType = 'text/plain'
    ) {
        parent::__construct(
            AttachmentType::DOCUMENT,
            $document,
            $type,
            $mediaType
        );
    }
}
