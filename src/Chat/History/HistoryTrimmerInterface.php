<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

interface HistoryTrimmerInterface
{
    public function getTotalTokens(): int;

    /**
     * Determine where to trim message history to fit within the context window.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    public function trim(array $messages, int $contextWindow): array;
}
