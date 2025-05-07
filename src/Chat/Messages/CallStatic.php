<?php

namespace NeuronAI\Chat\Messages;

use NeuronAI\StaticConstructor;

trait CallStatic
{
    use StaticConstructor;

    public static function assistant(array|string|int|float|null $content): AssistantMessage
    {
        return new AssistantMessage($content);
    }

    public static function toolCall(array|string|int|float|null $content, array $tools): ToolCallMessage
    {
        return new ToolCallMessage($content, $tools);
    }

    public static function toolCallResult(array $tools): ToolCallResultMessage
    {
        return new ToolCallResultMessage($tools);
    }

    public static function user(array|string|int|float|null $content): UserMessage
    {
        return new UserMessage($content);
    }
}
