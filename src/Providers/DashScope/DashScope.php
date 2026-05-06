<?php

declare(strict_types=1);

namespace NeuronAI\Providers\DashScope;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class DashScope extends OpenAI
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
            $content = $choice['delta']['content'] ?? null;
            if ($content !== null) {
                $this->streamState->updateContentBlock($choice['index'] ?? 0, new TextContent($content));
                yield new TextChunk($this->streamState->messageId(), $content);
            }
        }
    }
}
