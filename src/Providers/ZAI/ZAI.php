<?php

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class ZAI extends OpenAI
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleStructured;

    protected string $baseUri = 'https://api.z.ai/api/paas/v4';

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    protected function createAssistantMessage(array $message): AssistantMessage
    {
        $response = new AssistantMessage($message['content']);

        if (isset($message['reasoning_content'])) {
            $response->addContent(new ReasoningContent($message['reasoning_content']['content']));
        }

        return $response;
    }
}
