<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use Exception;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function max;
use function sprintf;
use function strtoupper;

class Summarization extends AgentMiddleware
{
    /**
     * @param AIProviderInterface|null $provider Writes the summary; the agent's own provider when null.
     */
    public function __construct(
        protected ?AIProviderInterface $provider = null,
        protected int $maxTokens = 50000,
        protected int $messagesToKeep = 5,
        protected ?string $summaryPrompt = null,
    ) {
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        // A non-positive threshold disables summarization.
        if ($this->maxTokens <= 0) {
            return;
        }

        $chatHistory = $resources->history;
        $messages = $chatHistory->getMessages();

        if (count($messages) <= $this->messagesToKeep) {
            return;
        }

        if ($chatHistory->calculateTotalUsage() <= $this->maxTokens) {
            return;
        }

        $this->summarizeHistory($chatHistory, $messages, $this->provider ?? $resources->provider);
    }

    /**
     * Replace the messages before the cutoff with a generated summary. The rewrite
     * clears the stored thread, so its archived messages are erased as well.
     *
     * @param Message[] $messages
     */
    protected function summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider): void
    {
        $cutoffIndex = $this->findSafeCutoffIndex($messages);

        if ($cutoffIndex === null || $cutoffIndex <= 0) {
            return;
        }

        $oldMessages = array_slice($messages, 0, $cutoffIndex);
        $recentMessages = array_slice($messages, $cutoffIndex);

        $summary = $this->generateSummary($provider, $oldMessages);
        if ($summary === null) {
            // Summarizing only saves tokens: without a summary the history stays
            // as it is, and the next inference tries again.
            return;
        }

        $newMessages = [
            new UserMessage("## Previous conversation summary:\n\n{$summary}"),
            ...$this->discountSummarizedTokens($recentMessages),
        ];

        $chatHistory->flushAll();
        foreach ($newMessages as $message) {
            $chatHistory->addMessage($message);
        }
    }

    /**
     * Kept messages carry the provider's cumulative input tokens, which still count the
     * summarized conversation: the cutoff message's input tokens measure it. Subtracting
     * them, as HistoryTrimmer does after a trim, stops the history from reading over the
     * limit again, which would summarize the summary on the next inference. The messages
     * are copies: the originals may still be held by the caller.
     *
     * @param Message[] $messages The kept messages, the cutoff message first
     * @return Message[]
     */
    protected function discountSummarizedTokens(array $messages): array
    {
        $summarizedTokens = $messages[0]->getUsage()->inputTokens ?? 0;

        return array_map(function (Message $message) use ($summarizedTokens): Message {
            $usage = $message->getUsage();
            if (!$usage instanceof Usage) {
                return $message;
            }

            return (clone $message)->setUsage(new Usage(
                max(0, $usage->inputTokens - $summarizedTokens),
                $usage->outputTokens,
                $usage->cachedInputTokens,
                $usage->reasoningTokens,
            ));
        }, $messages);
    }

    /**
     * A safe cutoff never separates a tool call message from its
     * corresponding tool result message and leaves an assistant message
     * as the first retained one, so it can follow the UserMessage summary;
     * the search walks backward from the target.
     *
     * @param Message[] $messages
     * @return int|null Index to cut at (exclusive), or null if no safe cutoff found
     */
    protected function findSafeCutoffIndex(array $messages): ?int
    {
        $totalMessages = count($messages);
        $targetCutoff = max(0, $totalMessages - $this->messagesToKeep);

        if ($targetCutoff <= 0) {
            return null;
        }

        for ($i = $targetCutoff; $i >= 0; $i--) {
            if ($this->isSafeCutoffPoint($messages, $i)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A cutoff is unsafe when the message at $index or the one before it is a
     * ToolCallMessage — either would separate a tool call from its result.
     * It is also unsafe when the message at $index is not an assistant message:
     * the summary is prepended as a UserMessage, and two consecutive user
     * messages break the role alternation.
     *
     * @param Message[] $messages
     */
    protected function isSafeCutoffPoint(array $messages, int $index): bool
    {
        if (isset($messages[$index]) && $messages[$index] instanceof ToolCallMessage) {
            return false;
        }

        if ($index > 0 && isset($messages[$index - 1]) && $messages[$index - 1] instanceof ToolCallMessage) {
            return false;
        }

        return isset($messages[$index]) && $messages[$index]->getRole() === MessageRole::ASSISTANT->value;
    }

    /**
     * The summary text, or null when the call fails or the reply has no text.
     *
     * @param Message[] $messages
     */
    protected function generateSummary(AIProviderInterface $provider, array $messages): ?string
    {
        $prompt = $this->summaryPrompt ?? $this->getDefaultSummaryPrompt();

        $conversation = $this->formatMessagesForSummarization($messages);

        try {
            $response = $provider
                ->systemPrompt('You are a helpful assistant that creates concise, informative summaries of conversations.')
                ->setTools([])
                ->chat(new UserMessage("{$prompt}\n\n{$conversation}"));

            return $response->message()->getContent();
        } catch (Exception) {
            return null;
        }
    }

    protected function getDefaultSummaryPrompt(): string
    {
        return <<<'PROMPT'
            Please provide a comprehensive summary of the following conversation.
            Extract the highest quality and most relevant pieces of information, including:
            - Key topics discussed
            - Important decisions made
            - Critical information exchanged
            - Action items or next steps
            - Any unresolved questions or issues

            Your summary should be concise yet informative, capturing the essential context
            that would be needed to continue the conversation meaningfully.
            PROMPT;
    }

    /**
     * @param Message[] $messages
     */
    protected function formatMessagesForSummarization(array $messages): string
    {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message->getRole();

            if ($message instanceof ToolCallMessage) {
                $toolNames = array_map(
                    fn (ToolCall $tool): string => $tool->getName(),
                    $message->getToolCalls()
                );
                $formatted[] = sprintf(
                    '[%s]: Called tools: %s',
                    strtoupper($role),
                    implode(', ', $toolNames)
                );
            } elseif ($message instanceof ToolResultMessage) {
                $formatted[] = sprintf(
                    '[%s]: Tool results received',
                    strtoupper($role)
                );
            } else {
                $contentStr = $message->getContent();
                $formatted[] = sprintf(
                    '[%s]: %s',
                    strtoupper($role),
                    $contentStr
                );
            }
        }

        return implode("\n", $formatted);
    }

    public function setMaxTokens(int $tokens): self
    {
        $this->maxTokens = $tokens;
        return $this;
    }

    public function setMessagesToKeep(int $count): self
    {
        $this->messagesToKeep = $count;
        return $this;
    }

    public function setSummaryPrompt(string $prompt): self
    {
        $this->summaryPrompt = $prompt;
        return $this;
    }
}
