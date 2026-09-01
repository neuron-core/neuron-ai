<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses\Stub;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class InspectableOpenAIResponses extends OpenAIResponses
{
    /**
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    public function buildRequestBody(array $messages, bool $stream = false): array
    {
        return $this->requestBody($messages, $stream);
    }
}
