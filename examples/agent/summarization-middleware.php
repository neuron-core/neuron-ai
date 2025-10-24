<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic;

/**
 * Example: Using Summarization Middleware
 *
 * This example demonstrates how to use the Summarization middleware to automatically
 * condense conversation history when it becomes too long.
 */

// Initialize providers
$mainProvider = Anthropic::make(
    apiKey: \getenv('ANTHROPIC_API_KEY'),
    model: 'claude-3-5-sonnet-20241022',
);

// Use a faster/cheaper model for summarization
$summarizationProvider = Anthropic::make(
    apiKey: \getenv('ANTHROPIC_API_KEY'),
    model: 'claude-3-5-haiku-20241022',
);

// Create agent with summarization middleware
$agent = Agent::make()
    ->setAiProvider($mainProvider)
    ->setInstructions('You are a helpful assistant with access to conversation history.')
    ->setChatHistory(new InMemoryChatHistory())
    // Apply summarization middleware to both ChatNode and StreamingNode
    ->middleware(
        [ChatNode::class, StreamingNode::class],
        new Summarization(
            provider: $summarizationProvider,
            maxTokensBeforeSummary: 5000,  // Trigger summarization after 5000 tokens
            messagesToKeep: 10,             // Keep last 10 messages
        )
    );

// Simulate a long conversation that will trigger summarization
$messages = [
    "Tell me about the history of artificial intelligence.",
    "What were the key breakthroughs in the 1950s?",
    "How did neural networks evolve over time?",
    "Explain the AI winter periods.",
    "What led to the modern deep learning revolution?",
    "Tell me about transformer models.",
    "What is the difference between GPT and BERT?",
    "Explain attention mechanisms.",
    "What are the ethical concerns around AI?",
    "How is AI being regulated globally?",
    "What are the latest developments in multimodal AI?",
    "Summarize our entire conversation so far.",
];

echo "Conversation Summarization Demo\n";
echo "=" . \str_repeat("=", 49) . "\n\n";

foreach ($messages as $index => $userMessage) {
    echo "\n[Turn " . ($index + 1) . "]\n";
    echo "User: {$userMessage}\n";

    try {
        $response = $agent->chat(UserMessage::make($userMessage));
        $content = $response->getContent();

        echo "Assistant: " . \substr($content, 0, 200);
        if (\strlen($content) > 200) {
            echo "...\n";
        } else {
            echo "\n";
        }

        // Show token count and message count
        $chatHistory = $agent->state->getChatHistory();
        $messageCount = \count($chatHistory->getMessages());
        $totalTokens = $chatHistory->calculateTotalUsage();

        echo "\n[Stats: {$messageCount} messages, ~{$totalTokens} tokens]\n";

        // Check if first message is a summary
        $firstMessage = $chatHistory->getMessages()[0] ?? null;
        if ($firstMessage && \str_contains($firstMessage->getContent(), '## Previous conversation summary:')) {
            echo "[INFO: History was summarized!]\n";
        }

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "\n" . \str_repeat("=", 50) . "\n";
echo "Demo completed!\n";

/**
 * Advanced Usage Examples
 */

// Example 1: Custom summarization prompt
$customSummarizationMiddleware = new Summarization(
    provider: $summarizationProvider,
    maxTokensBeforeSummary: 10000,
    messagesToKeep: 20,
    summaryPrompt: <<<'PROMPT'
Analyze the conversation and provide:
1. Main topics discussed
2. Key technical details mentioned
3. Any code examples or specific implementations
4. Outstanding questions or unresolved issues

Format the summary in a structured way.
PROMPT
);

// Example 2: Custom token counter
$customTokenCounterMiddleware = new Summarization(
    provider: $summarizationProvider,
    maxTokensBeforeSummary: 10000,
    messagesToKeep: 20,
    tokenCounter: function (array $messages): int {
        // Custom token counting logic
        // For example, using tiktoken or another tokenizer
        $totalTokens = 0;
        foreach ($messages as $message) {
            // Your custom counting logic here
            $content = $message->getContent();
            $contentStr = \is_array($content) ? \json_encode($content) : (string) $content;
            $totalTokens += \str_word_count($contentStr) * 1.3; // Rough approximation
        }
        return (int) $totalTokens;
    }
);

// Example 3: Fluent API for configuration
$fluentMiddleware = (new Summarization(provider: $summarizationProvider))
    ->setMaxTokensBeforeSummary(8000)
    ->setMessagesToKeep(15)
    ->setSummaryPrompt('Create a brief summary focusing on technical details.')
    ->setTokenCounter(fn ($messages) => \array_sum(
        \array_map(fn ($msg) => \strlen((string) $msg->getContent()), $messages)
    ) / 4);

// Example 4: Disable summarization dynamically
$disabledSummarization = new Summarization(
    provider: $summarizationProvider,
    maxTokensBeforeSummary: 0,  // 0 or negative disables summarization
);

// Example 5: Aggressive summarization (keep fewer messages)
$aggressiveSummarization = new Summarization(
    provider: $summarizationProvider,
    maxTokensBeforeSummary: 3000,  // Lower threshold
    messagesToKeep: 5,              // Keep fewer messages
);

echo "\nAdvanced configuration examples shown in code comments.\n";
echo "Check the source code for more details!\n";
