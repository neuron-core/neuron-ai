<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;

use function array_map;
use function array_sum;
use function str_repeat;
use function strlen;

/**
 * Builds a conversation carrying the usage a provider would report: each measured
 * answer's input is the overhead (instructions and tools) plus the tokens of every
 * earlier message, and its output is the answer's own size. User messages and tool
 * results get texts the TokenCounter measures at exactly the requested tokens.
 */
class Conversation
{
    /**
     * @var Message[]
     */
    protected array $messages = [];

    public function __construct(protected int $overhead = 0)
    {
    }

    /**
     * A user message starting with $label: the role and block framing take 42 characters,
     * so the smallest size is a quarter of the label's length plus 42.
     */
    public function user(int $tokens, string $label = 'question'): static
    {
        $this->messages[] = new UserMessage(self::text($tokens, $label));

        return $this;
    }

    public function answer(int $tokens, string $text = 'answer'): static
    {
        $this->messages[] = (new AssistantMessage($text))->setUsage(self::usage($this->messages, $this->overhead, $tokens));

        return $this;
    }

    public function toolCall(int $tokens, string $callId): static
    {
        $this->messages[] = (new ToolCallMessage(null, [new ToolCall('lookup', $callId)]))
            ->setUsage(self::usage($this->messages, $this->overhead, $tokens));

        return $this;
    }

    public function toolResult(int $tokens, string $callId): static
    {
        $this->messages[] = new ToolResultMessage([(new ToolCall('lookup', $callId))->setResult(self::result($tokens, $callId))]);

        return $this;
    }

    /**
     * @return Message[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The usage a provider reports for an answer of $output tokens to $context.
     *
     * @param Message[] $context
     */
    public static function usage(array $context, int $overhead, int $output): Usage
    {
        return new Usage($overhead + array_sum(array_map(self::tokens(...), $context)), $output);
    }

    /**
     * A message's own tokens: a measured answer's output, the estimate otherwise.
     */
    public static function tokens(Message $message): int
    {
        $usage = $message instanceof AssistantMessage ? $message->getUsage() : null;

        return $usage instanceof Usage ? $usage->outputTokens : (new TokenCounter())->count($message);
    }

    /**
     * A tool result the TokenCounter measures at exactly $tokens, with its call ID.
     */
    public static function result(int $tokens, string $callId): string
    {
        // 4 role characters and the call ID count alongside the result
        return str_repeat('r', $tokens * 4 - 4 - strlen($callId));
    }

    /**
     * A user message text the TokenCounter measures at exactly $tokens.
     */
    public static function text(int $tokens, string $label = 'question'): string
    {
        // 4 role characters and 38 of block JSON frame the content
        return $label . str_repeat('.', $tokens * 4 - 42 - strlen($label));
    }
}
