<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Attachments;

use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;
use NeuronAI\StaticConstructor;

class Attachment implements \JsonSerializable
{
    use StaticConstructor;

    public function __construct(
        public AttachmentType $type,
        public string $content,
        public AttachmentContentType $contentType = AttachmentContentType::URL,
        public ?string $mediaType = null
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return \array_filter([
            'type' => $this->type->value,
            'content' => $this->content,
            'content_type' => $this->contentType->value,
            'media_type' => $this->mediaType,
        ]);
    }
}
