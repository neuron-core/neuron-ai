<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class Summarization implements WorkflowMiddleware
{
    /**
     * @var callable
     */
    public $tokenCounter;
    public function __construct(
        protected AIProviderInterface $provider,
        protected int $maxTokensBeforeSummary = 10000,
        protected int $messagesToKeep = 10,
        protected ?string $summaryPrompt = null,
    ) {
    }

    /**
     * Execute before the node runs.
     *
     * Checks if summarization is needed based on token count and performs
     * the summarization if the threshold is exceeded.
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        // Only apply to ChatNode and StreamingNode
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        // Summarization disabled
        if ($this->maxTokensBeforeSummary <= 0) {
            return;
        }

        // Type hint for IDE
        if (!$state instanceof AgentState) {
            return;
        }

        $chatHistory = $state->getChatHistory();
        $messages = $chatHistory->getMessages();

        // Not enough messages to warrant summarization
        if (\count($messages) <= $this->messagesToKeep) {
            return;
        }

        // Threshold isn't exceeded
        if ($chatHistory->calculateTotalUsage() <= $this->maxTokensBeforeSummary) {
            return;
        }

        // Perform summarization
        $this->summarizeHistory($state, $messages);
    }

    /**
     * Execute after the node runs.
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
    {
        // No action needed after node execution
    }

    /**
     * Summarize chat history by replacing old messages with a summary.
     *
     * @param Message[] $messages
     */
    protected function summarizeHistory(AgentState $state, array $messages): void
    {
        // Find a safe cutoff point
        $cutoffIndex = $this->findSafeCutoffIndex($messages);

        echo "\n\nCutoff Index: " . $cutoffIndex . "\n\n";

        // If no safe cutoff found or not enough messages to summarize, skip
        if ($cutoffIndex === null || $cutoffIndex <= 0) {
            return;
        }

        // Split messages into old (to summarize) and recent (to keep)
        $oldMessages = \array_slice($messages, 0, $cutoffIndex);
        $recentMessages = \array_slice($messages, $cutoffIndex);

        // Generate summary of old messages
        $summary = $this->generateSummary($oldMessages);

        // Create the new message list: summary + recent messages
        $newMessages = [
            new UserMessage("## Previous conversation summary:\n\n{$summary}"),
            ...$recentMessages,
        ];

        // Update chat history
        $state->getChatHistory()->setMessages($newMessages);
    }

    /**
     * Find a safe cutoff index that doesn't break tool call sequences.
     *
     * A safe cutoff point is one where we don't separate a tool call message
     * from its corresponding tool result message.
     *
     * @param Message[] $messages
     * @return int|null Index to cut at (exclusive), or null if no safe cutoff found
     */
    protected function findSafeCutoffIndex(array $messages): ?int
    {
        $totalMessages = \count($messages);
        $targetCutoff = \max(0, $totalMessages - $this->messagesToKeep);

        // If target cutoff is at the beginning, nothing to summarize
        if ($targetCutoff <= 0) {
            return null;
        }

        // Search backward from target to find a safe cutoff point
        for ($i = $targetCutoff; $i >= 0; $i--) {
            if ($this->isSafeCutoffPoint($messages, $i)) {
                return $i;
            }
        }

        // No safe cutoff found
        return null;
    }

    /**
     * Check if a given index is a safe cutoff point.
     *
     * A cutoff is safe if:
     * 1. The message at "index" is not a ToolCallMessage (would leave tool call without result)
     * 2. The previous message is not a ToolCallMessage (would separate tool call from result)
     *
     * @param Message[] $messages
     */
    protected function isSafeCutoffPoint(array $messages, int $index): bool
    {
        // Check if a message at cutoff index is a ToolCallMessage
        if (isset($messages[$index]) && $messages[$index] instanceof ToolCallMessage) {
            return false;
        }
        // Check if a previous message is a ToolCallMessage (would be separated from its result)
        return !($index > 0 && isset($messages[$index - 1]) && $messages[$index - 1] instanceof ToolCallMessage);
    }

    /**
     * Generate a summary of the provided messages using the AI provider.
     *
     * @param Message[] $messages
     */
    protected function generateSummary(array $messages): string
    {
        $prompt = $this->summaryPrompt ?? $this->getDefaultSummaryPrompt();

        // Format messages into a readable conversation format
        $conversation = $this->formatMessagesForSummarization($messages);

        // Create summarization request
        $summaryRequest = [
            UserMessage::make("{$prompt}\n\n{$conversation}"),
        ];

        try {
            // Call AI provider to generate summary
            $response = $this->provider
                ->systemPrompt('You are a helpful assistant that creates concise, informative summaries of conversations.')
                ->chat($summaryRequest);

            return $response->getTextContent();
        } catch (\Exception) {
            // If summarization fails, return a basic fallback summary
            return \sprintf(
                'Previous conversation contained %d messages covering various topics.',
                \count($messages)
            );
        }
    }

    /**
     * Get the default summarization prompt.
     */
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
     * Format messages into a readable conversation format for summarization.
     *
     * @param Message[] $messages
     */
    protected function formatMessagesForSummarization(array $messages): string
    {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message->getRole();

            if ($message instanceof ToolCallMessage) {
                $toolNames = \array_map(
                    fn (\NeuronAI\Tools\ToolInterface $tool): string => $tool->getName(),
                    $message->getTools()
                );
                $formatted[] = \sprintf(
                    '[%s]: Called tools: %s',
                    \strtoupper($role),
                    \implode(', ', $toolNames)
                );
            } elseif ($message instanceof ToolResultMessage) {
                $formatted[] = \sprintf(
                    '[%s]: Tool results received',
                    \strtoupper($role)
                );
            } else {
                // Regular message - extract text content from blocks
                $contentStr = $message->getTextContent();
                $formatted[] = \sprintf(
                    '[%s]: %s',
                    \strtoupper($role),
                    $contentStr
                );
            }
        }

        return \implode("\n", $formatted);
    }

    /**
     * Count total tokens in messages.
     *
     * Uses custom token counter if provided, otherwise uses default estimation.
     *
     * @param Message[] $messages
     */
    protected function countTokens(array $messages): int
    {
        // Default token counting: use usage data if available, otherwise estimate
        $totalTokens = 0;

        foreach ($messages as $message) {
            $usage = $message->getUsage();
            if ($usage !== null) {
                // Use actual token count from usage data
                $totalTokens += $usage->getTotal();
            } else {
                // Estimate tokens (rough approximation: 1 token ≈ 4 characters)
                $contentStr = $message->getTextContent();
                $totalTokens += (int) \ceil(\strlen($contentStr) / 4);
            }
        }

        return $totalTokens;
    }

    /**
     * Set the maximum tokens before summarization threshold.
     */
    public function setMaxTokensBeforeSummary(int $tokens): self
    {
        $this->maxTokensBeforeSummary = $tokens;
        return $this;
    }

    /**
     * Set the number of messages to keep after summarization.
     */
    public function setMessagesToKeep(int $count): self
    {
        $this->messagesToKeep = $count;
        return $this;
    }

    /**
     * Set a custom summarization prompt.
     */
    public function setSummaryPrompt(string $prompt): self
    {
        $this->summaryPrompt = $prompt;
        return $this;
    }

    /**
     * Set a custom token counter function.
     *
     * @param callable $counter Function that takes Message[] and returns int
     */
    public function setTokenCounter(callable $counter): self
    {
        $this->tokenCounter = $counter;
        return $this;
    }
}
