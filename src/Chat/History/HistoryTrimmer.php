<?php

namespace NeuronAI\Chat\History;

class HistoryTrimmer implements HistoryTrimmerInterface
{

    public function tokenCount(array $messages): int
    {
        // TODO: Implement tokenCount() method.
    }

    public function trimIndex(array $messages, int $contextWindow): int
    {
        $left = 0;
        $right = count($messages);

        while ($left < $right) {
            $mid = intval(($left + $right) / 2);
            $subset = array_slice($messages, $mid);

            if ($this->tokenCount($subset) <= $contextWindow) {
                // Fits! Try including more messages (skip fewer)
                $right = $mid;
            } else {
                // Doesn't fit! Need to skip more messages
                $left = $mid + 1;
            }
        }

        return $left;
    }
}
