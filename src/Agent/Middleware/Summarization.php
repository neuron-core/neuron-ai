<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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
    public function __construct(
        protected AIProviderInterface $provider,
        protected int $maxTokens = 50000,
        protected int $messagesToKeep = 5,
        protected ?string $summaryPrompt = null,
    ) {
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        // A non-positive threshold disables summarization.
        if ($this->maxTokens <= 0) {
            return;
        }

        $chatHistory = $node->getChatHistory();
        $messages = $chatHistory->getMessages();

        if (count($messages) <= $this->messagesToKeep) {
            return;
        }

        if ($chatHistory->calculateTotalUsage() <= $this->maxTokens) {
            return;
        }

        $this->summarizeHistory($chatHistory, $messages);
    }

    /**
     * Replace the messages before the cutoff with a generated summary.
     *
     * @param Message[] $messages
     */
    protected function summarizeHistory(ChatHistoryInterface $chatHistory, array $messages): void
    {
        $cutoffIndex = $this->findSafeCutoffIndex($messages);

        if ($cutoffIndex === null || $cutoffIndex <= 0) {
            return;
        }

        $oldMessages = array_slice($messages, 0, $cutoffIndex);
        $recentMessages = array_slice($messages, $cutoffIndex);

        $summary = $this->generateSummary($oldMessages);

        $newMessages = [
            new UserMessage("## Previous conversation summary:\n\n{$summary}"),
            ...$recentMessages,
        ];

        $chatHistory->flushAll();
        foreach ($newMessages as $message) {
            $chatHistory->addMessage($message);
        }
    }

    /**
     * A safe cutoff never separates a tool call message from its
     * corresponding tool result message; the search walks backward
     * from the target.
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
     *
     * @param Message[] $messages
     */
    protected function isSafeCutoffPoint(array $messages, int $index): bool
    {
        if (isset($messages[$index]) && $messages[$index] instanceof ToolCallMessage) {
            return false;
        }

        return !($index > 0 && isset($messages[$index - 1]) && $messages[$index - 1] instanceof ToolCallMessage);
    }

    /**
     * @param Message[] $messages
     */
    protected function generateSummary(array $messages): string
    {
        $prompt = $this->summaryPrompt ?? $this->getDefaultSummaryPrompt();

        $conversation = $this->formatMessagesForSummarization($messages);

        try {
            $response = $this->provider
                ->systemPrompt('You are a helpful assistant that creates concise, informative summaries of conversations.')
                ->chat(new UserMessage("{$prompt}\n\n{$conversation}"));

            return $response->message()->getContent();
        } catch (Exception) {
            // A failed summarization degrades to a placeholder rather than
            // failing the run.
            return sprintf(
                'Previous conversation contained %d messages covering various topics.',
                count($messages)
            );
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
