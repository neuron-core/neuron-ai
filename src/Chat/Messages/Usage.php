<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use JsonSerializable;

class Usage implements JsonSerializable
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheWriteTokens = 0,
        public int $cacheReadTokens = 0,
    ) {
    }

    public function getTotal(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
        ];
    }
}
