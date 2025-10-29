<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Enums\MessageRole;

/**
 * @method static static make(string|ContentBlock|array<int, ContentBlock>|null $content = null)
 */
class UserMessage extends Message
{
    /**
     * @param string|ContentBlock|ContentBlock[]|null $content
     */
    public function __construct(string|ContentBlock|array|null $content)
    {
        parent::__construct(MessageRole::USER, $content);
    }
}
