<?php

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

abstract class AbstractChatHistory implements ChatHistoryInterface
{
    protected array $history = [];

    public function __construct(protected int $contextWindow = 50000) {}

    protected function updateUsedTokens(Message $message): void
    {
        if ($message->getUsage()) {
            // For every new message, we store only the marginal contribution of input tokens
            // of the latest interactions.
            $previousInputConsumption = \array_reduce($this->history, function ($carry, Message $message) {
                if ($message->getUsage()) {
                    $carry += $message->getUsage()->inputTokens;
                }
                return $carry;
            }, 0);

            // Subtract the previous input consumption.
            $message->getUsage()->inputTokens = $message->getUsage()->inputTokens - $previousInputConsumption;
        }
    }

    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->updateUsedTokens($message);

        $this->history[] = $message;
        $this->storeMessage($message);

        $this->cutHistoryToContextWindow();

        return $this;
    }

    abstract protected function storeMessage(Message $message): ChatHistoryInterface;

    public function getMessages(): array
    {
        return $this->history;
    }

    public function getLastMessage(): Message
    {
        return \end($this->history);
    }

    abstract public function removeOldestMessage(): ChatHistoryInterface;

    abstract protected function clear(): ChatHistoryInterface;

    public function flushAll(): ChatHistoryInterface
    {
        $this->clear();
        $this->history = [];
        return $this;
    }

    public function calculateTotalUsage(): int
    {
        return \array_reduce($this->history, function (int $carry, Message $message) {
            if ($message->getUsage()) {
                $carry += $message->getUsage()->getTotal();
            }

            return $carry;
        }, 0);
    }

    protected function cutHistoryToContextWindow(): void
    {
        if ($this->getFreeMemory() >= 0) {
            return;
        }

        // Cut old messages
        do {
            $this->removeOldestMessage();
            if (\array_shift($this->history) === null) {
                break;
            }
        } while ($this->getFreeMemory() < 0);
    }

    public function getFreeMemory(): int
    {
        return $this->contextWindow - $this->calculateTotalUsage();
    }

    public function jsonSerialize(): array
    {
        return $this->getMessages();
    }

    protected function unserializeMessages(array $messages): array
    {
        return \array_map(function (array $message) {
            return match ($message['type']??null) {
                'tool_call' => $this->unserializeToolCall($message),
                'tool_call_result' => $this->unserializeToolCallResult($message),
                default => $this->unserializeMessage($message),
            };
        }, $messages);
    }

    protected function unserializeMessage(array $message): Message
    {
        $item = match ($message['role']){
            Message::ROLE_ASSISTANT => new AssistantMessage($message['content']??''),
            Message::ROLE_USER => new UserMessage($message['content']??''),
            default => new Message($message['role'], $message['content']??'')
        };

        $this->unserializeMeta($message, $item);

        return $item;
    }

    protected function unserializeToolCall(array $message): ToolCallMessage
    {
        $tools = \array_map(function (array $tool) {
            return Tool::make($tool['name'], $tool['description'])
                ->setInputs($tool['inputs'])
                ->setCallId($tool['callId']);
        }, $message['tools']);

        $item = new ToolCallMessage($message['content'], $tools);

        $this->unserializeMeta($message, $item);

        return $item;
    }

    protected function unserializeToolCallResult(array $message): ToolCallResultMessage
    {
        $tools = \array_map(function (array $tool) {
            return Tool::make($tool['name'], $tool['description'])
                ->setInputs($tool['inputs'])
                ->setCallId($tool['callId'])
                ->setResult($tool['result']);
        }, $message['tools']);

        return new ToolCallResultMessage($tools);
    }

    /**
     * @param array $message
     * @param ToolCallMessage $item
     * @return void
     */
    protected function unserializeMeta(array $message, Message $item): void
    {
        foreach ($message as $key => $value) {
            if ($key === 'role' || $key === 'content') {
                continue;
            }
            if ($key === 'usage') {
                $item->setUsage(
                    new Usage($message['usage']['input_tokens'], $message['usage']['output_tokens'])
                );
                continue;
            }
            if ($key === 'images') {
                foreach ($message['images'] as $image) {
                    $item->addImage(new Image($image['image'], $image['type'], $image['media_type'] ?? null));
                }
                continue;
            }
            $item->addMetadata($key, $value);
        }
    }
}
