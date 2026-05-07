<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Alibaba;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class DashScopeOpenAI extends OpenAI
{
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
        protected string $baseUri = 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);
    }

    protected function processContentDelta(array $choice): Generator
    {
        $reasoningContent = $choice['delta']['reasoning_content'] ?? null;
        if ($reasoningContent !== null) {
            $this->streamState->updateContentBlock(
                $choice['index'],
                new ReasoningContent($reasoningContent)
            );
            yield new ReasoningChunk($this->streamState->messageId(), $reasoningContent);
        } else {
            yield from parent::processContentDelta($choice);
        }
    }
}
