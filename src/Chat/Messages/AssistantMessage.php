<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Enums\MessageRole;

/**
 * @method static static make(string|ContentBlockInterface|array<int, ContentBlockInterface>|null $content = null, MessageRole $role = MessageRole::ASSISTANT)
 */
class AssistantMessage extends Message
{
    /**
     * @param string|ContentBlockInterface|ContentBlockInterface[]|null $content
     */
    public function __construct(string|ContentBlockInterface|array|null $content = null, MessageRole $role = MessageRole::ASSISTANT)
    {
        parent::__construct($role, $content);
    }

    public function setStopReason(string $reason): self
    {
        return $this->addMetadata('stop_reason', $reason);
    }

    public function stopReason(): ?string
    {
        return $this->getMetadata('stop_reason');
    }
}
