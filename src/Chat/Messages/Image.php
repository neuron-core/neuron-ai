<?php

namespace NeuronAI\Chat\Messages;

class Image implements \JsonSerializable
{
    const TYPE_URL = 'url';
    const TYPE_BASE64 = 'base64';

    public function __construct(
        public string $image,
        public string $type = self::TYPE_URL,
        public ?string $mediaType = null
    ) {}

    public function jsonSerialize(): array
    {
        return \array_filter([
            'image' => $this->image,
            'type' => $this->type,
            'media_type' => $this->mediaType,
        ]);
    }
}
