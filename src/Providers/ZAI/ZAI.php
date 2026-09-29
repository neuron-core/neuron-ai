<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\ToolMapperInterface;

class ZAI extends OpenAI
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleStructured;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
        protected string $baseUri = 'https://api.z.ai/api/paas/v4',
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    protected function createAssistantMessage(array $message): AssistantMessage
    {
        $response = new AssistantMessage($message['content']);

        if (isset($message['reasoning_content'])) {
            $response->addContent(new ReasoningContent($message['reasoning_content']));
        }

        return $response;
    }

    /**
     * GLM thinking models stream their reasoning in reasoning_content, beside the answer.
     *
     * @return Generator<StreamChunk>
     */
    protected function processContentDelta(array $choice): Generator
    {
        $reasoning = $choice['delta']['reasoning_content'] ?? '';

        if ($reasoning !== '') {
            // A key of its own keeps the reasoning ahead of the answer's text block
            $this->streamState->updateContentBlock(-1, new ReasoningContent($reasoning));
            yield new ReasoningChunk($this->streamState->messageId(), $reasoning);
        }

        yield from parent::processContentDelta($choice);
    }
}
