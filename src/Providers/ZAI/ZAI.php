<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI;

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

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
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
}
