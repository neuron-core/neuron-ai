<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use Stringable;

/**
 * @deprecated Use TextContent instead: every block takes cache(), and a SystemMessage
 * reads any text block. SystemContent will be removed in the next major version.
 */
class SystemContent extends TextContent implements Stringable
{
    public function __construct(string $content, bool $cached = false)
    {
        parent::__construct($content);
        $this->cached = $cached;
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::SYSTEM;
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
