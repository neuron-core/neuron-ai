<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;

/**
 * Example: Using Summarization Middleware
 *
 * This example demonstrates how to use the Summarization middleware to automatically
 * condense conversation history when it becomes too long.
 */

// Initialize providers
$mainProvider = new Anthropic(
    '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',
    'claude-3-5-sonnet-20241022',
);

// Use a faster/cheaper model for summarization
$summarizationProvider = new Anthropic(
    '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',
    'claude-3-5-haiku-20241022',
);

// Create the agent with summarization middleware
$agent = Agent::make()
    ->setAiProvider($mainProvider)
    ->setChatHistory(new InMemoryChatHistory())
    // Apply summarization middleware to generative nodes
    ->addMiddleware(
        [ChatNode::class, StreamingNode::class, StructuredOutputNode::class],
        new Summarization(
            provider: $summarizationProvider,
            maxTokensBeforeSummary: 1000,
            messagesToKeep: 3,
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
        $chatHistory = $agent->resolveAgentState()->getChatHistory();
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
