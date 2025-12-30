<?php

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

interface HistoryTrimmerInterface
{
    /**
     * @param Message[] $messages
     */
    public function tokenCount(array $messages): int;

    /**
     * Determine where to trim message history to fit within the context window.
     *
     * @param Message[] $messages
     * @return int Index of the first message to keep (0 = keep all, count($messages) = skip all)
     */
    public function trimIndex(array $messages, int $contextWindow): int;
}
