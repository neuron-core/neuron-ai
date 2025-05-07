<?php

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapper implements MessageMapperInterface
{
    /**
     * Mapped messages.
     *
     * @var array
     */
    protected array $mapping = [];

    public function map(array $messages): array
    {
        foreach ($messages as $message) {
            match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                ToolCallResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };
        }

        return $this->mapping;
    }

    public function mapMessage(Message $message): void
    {
        $payload = $message->jsonSerialize();

        if (\array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        if ($images = $message->getImages()) {
            $payload['images'] = \array_map(fn (Image $image) => $this->mapImage($image), $images);
        }

        $this->mapping[] = $payload;
    }

    protected function mapImage(Image $image)
    {
        return match($image->type) {
            Image::TYPE_BASE64 => $image->image,
            // Transform url in base64 could be a security issue. So we raise an exception.
            Image::TYPE_URL => throw new ProviderException('Ollama support only base64 image type.'),
        };
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $message = $message->jsonSerialize();

        if (\array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        if (\array_key_exists('tool_calls', $message)) {
            $message['tool_calls'] = \array_map(function (array $toolCall) {
                if (empty($toolCall['function']['arguments'])) {
                    $toolCall['function']['arguments'] = new \stdClass();
                }
                return $toolCall;
            }, $message['tool_calls']);
        }

        unset($message['type']);
        unset($message['tools']);

        $this->mapping[] = $message;
    }

    public function mapToolsResult(ToolCallResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => Message::ROLE_TOOL,
                'content' => $tool->getResult()
            ];
        }
    }
}
