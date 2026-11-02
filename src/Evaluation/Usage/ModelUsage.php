<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Usage;

use function array_values;

/**
 * Immutable value object: what the calls to one model of one provider consumed.
 * No record at all means nothing was measured, never that the calls were free.
 * The framework counts tokens and leaves their price to whoever reads them.
 */
class ModelUsage
{
    /**
     * @param string $provider The provider's class: one model name can be served by several.
     * @param int $inputTokens The prompt as the provider reports it, cachedInputTokens and cacheWriteTokens included,
     *        plus what the provider's own tools read, which is billed as input.
     * @param int $reasoningTokens Part of outputTokens, except on providers that report it apart, such as Gemini and Grok.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $calls = 0,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cachedInputTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $reasoningTokens = 0,
    ) {
    }

    /**
     * One record per provider and model, whatever the order of the records given.
     *
     * @param array<ModelUsage> $records
     * @return array<ModelUsage>
     */
    public static function merge(array $records): array
    {
        $merged = [];

        foreach ($records as $record) {
            $key = "{$record->provider}|{$record->model}";
            $merged[$key] = isset($merged[$key]) ? $merged[$key]->plus($record) : $record;
        }

        return array_values($merged);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->provider,
            $this->model,
            $this->calls + $other->calls,
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cachedInputTokens + $other->cachedInputTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->reasoningTokens + $other->reasoningTokens,
        );
    }

    /**
     * @return array{provider: string, model: string, calls: int, input_tokens: int, output_tokens: int, cached_input_tokens: int, cache_write_tokens: int, reasoning_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'calls' => $this->calls,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
