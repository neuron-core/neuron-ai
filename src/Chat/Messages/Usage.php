<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use JsonSerializable;

class Usage implements JsonSerializable
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        /**
         * Input tokens served from the provider prompt cache, billed at a
         * reduced rate. Whether this count is already part of `inputTokens`
         * is provider-specific: OpenAI includes cached tokens in
         * `input_tokens`, while Anthropic reports `cache_read_input_tokens`
         * separately from `input_tokens`. Stays `0` for providers without a
         * prompt cache or when no cache hit occurred.
         */
        public int $cachedInputTokens = 0,
        /**
         * Output tokens spent on internal reasoning by reasoning-capable
         * models (e.g. OpenAI o-series / GPT-5, Gemini thinking). Whether
         * this count is already part of `outputTokens` is provider-specific:
         * OpenAI includes reasoning tokens in `output_tokens`, while Gemini
         * reports `thoughtsTokenCount` separately from `candidatesTokenCount`
         * (so for Gemini `getTotal()` does not account for them). Stays `0`
         * for non-reasoning models.
         */
        public int $reasoningTokens = 0,
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
            'cached_input_tokens' => $this->cachedInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
