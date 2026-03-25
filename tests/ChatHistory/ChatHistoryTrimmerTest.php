<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function count;
use function sort;
use function str_repeat;
use function uniqid;

class ChatHistoryTrimmerTest extends TestCase
{
    private const CONTEXT_WINDOW = 200000; // 200K context window

    private InMemoryChatHistory $chatHistory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chatHistory = new InMemoryChatHistory(self::CONTEXT_WINDOW);
    }

    protected function tearDown(): void
    {
        $this->chatHistory->flushAll();
    }

    public function test_trimmer_with_high_number_of_messages(): void
    {
        // Simulate a conversation that exceeds 200K tokens
        // We'll create a mix of:
        // - Regular user/assistant pairs
        // - User + tool_call + tool_result + assistant sequences
        //
        // Each "iteration" represents a conversation turn.
        // We'll exceed 200K and continue a bit more to test trimming efficiency.

        $iterations = 0;
        $maxIterations = 500; // Safety limit
        $continueAfterExceed = 50; // Continue these many iterations after exceeding 200K

        $exceededWindow = false;
        $iterationsAfterExceed = 0;

        while ($iterations < $maxIterations) {
            $iterations++;

            // Each turn adds roughly 2000-2500 tokens
            // We'll reach 200K around iteration 80-100
            $turnTokens = 2000 + ($iterations % 500);

            // Use actual usage as the base after trimming occurs,
            // simulating realistic AI provider behavior where inputTokens
            // reflects the actual context sent (not historical cumulative)
            $currentUsage = $this->chatHistory->calculateTotalUsage();
            $cumulativeTokens = $currentUsage + $turnTokens;

            // Alternate between regular pairs and tool-using sequences
            if ($iterations % 3 === 0) {
                // Tool-using sequence: user -> tool_call -> tool_result -> assistant
                $this->addToolSequence($iterations, $cumulativeTokens);
            } else {
                // Regular pair: user -> assistant
                $this->addRegularPair($iterations, $cumulativeTokens);
            }

            $totalUsage = $this->chatHistory->calculateTotalUsage();

            if (!$exceededWindow && $totalUsage > self::CONTEXT_WINDOW) {
                $exceededWindow = true;
            }

            if ($exceededWindow) {
                $iterationsAfterExceed++;
                if ($iterationsAfterExceed >= $continueAfterExceed) {
                    break;
                }
            }
        }

        // Verify the trimming keeps us within the context window
        $finalUsage = $this->chatHistory->calculateTotalUsage();
        $this->assertLessThanOrEqual(
            self::CONTEXT_WINDOW,
            $finalUsage,
            "Final usage ($finalUsage) should be within context window (" . self::CONTEXT_WINDOW . ")"
        );

        // Verify message sequence validity
        $messages = $this->chatHistory->getMessages();
        $this->assertGreaterThan(0, count($messages), 'Should have messages after trimming');

        // Should start with user message
        $this->assertInstanceOf(
            UserMessage::class,
            $messages[0],
            'History should start with a user message'
        );

        // Should end with assistant message
        $lastMessage = $messages[count($messages) - 1];
        $this->assertInstanceOf(
            AssistantMessage::class,
            $lastMessage,
            'History should end with an assistant message'
        );

        // Verify tool call/result pair integrity
        $this->assertToolPairsAreValid($messages);

        // Verify message alternation is valid
        $this->assertMessageAlternationIsValid($messages);

        // Performance assertion: trimming should be efficient
        // After many iterations beyond the window, we should still have a reasonable number of messages
        $this->assertLessThan(
            1000,
            count($messages),
            'Trimmed history should not have excessive message count'
        );
    }

    private function addRegularPair(int $iteration, int $cumulativeTokens): void
    {
        // Create substantial content to simulate real usage
        $userContent = $this->generateContent($iteration, 'user');
        $assistantContent = $this->generateContent($iteration, 'assistant');

        $this->chatHistory->addMessage(new UserMessage($userContent));

        $assistant = new AssistantMessage($assistantContent);
        // Cumulative usage: input_tokens includes all prior context
        $outputTokens = 300 + ($iteration % 200);
        $assistant->setUsage(new Usage($cumulativeTokens - $outputTokens, $outputTokens));
        $this->chatHistory->addMessage($assistant);
    }

    private function addToolSequence(int $iteration, int $cumulativeTokens): void
    {
        $toolName = "tool_{$iteration}";
        $callId = "call_{$iteration}_" . uniqid();

        $tool = Tool::make($toolName, "Tool number {$iteration}")
            ->setInputs(['param' => "value_{$iteration}", 'data' => $this->generateContent($iteration, 'tool_input')])
            ->setCallId($callId);

        $toolWithResult = Tool::make($toolName, "Tool number {$iteration}")
            ->setInputs(['param' => "value_{$iteration}", 'data' => $this->generateContent($iteration, 'tool_input')])
            ->setCallId($callId)
            ->setResult($this->generateContent($iteration, 'tool_result'));

        // User message
        $this->chatHistory->addMessage(new UserMessage($this->generateContent($iteration, 'user')));

        // Tool call
        $toolCall = new ToolCallMessage(tools: [$tool]);
        $outputTokens1 = 100;
        $toolCall->setUsage(new Usage($cumulativeTokens - 600 - $outputTokens1, $outputTokens1));
        $this->chatHistory->addMessage($toolCall);

        // Tool result
        $this->chatHistory->addMessage(new ToolResultMessage([$toolWithResult]));

        // Assistant response - this is where the checkpoint is
        $assistant = new AssistantMessage($this->generateContent($iteration, 'assistant'));
        $outputTokens2 = 500;
        $assistant->setUsage(new Usage($cumulativeTokens - $outputTokens2, $outputTokens2));
        $this->chatHistory->addMessage($assistant);
    }

    private function generateContent(int $iteration, string $type): string
    {
        // Generate realistic content that would contribute to token count
        $baseContent = match ($type) {
            'user' => "User request iteration {$iteration}: Please help me with a complex task that requires detailed analysis.",
            'assistant' => "Assistant response iteration {$iteration}: I'll help you analyze this thoroughly. Here's my detailed breakdown...",
            'tool_input' => "Tool input data for iteration {$iteration} with parameters and configuration.",
            'tool_result' => "Tool execution result for iteration {$iteration}: Successfully processed with detailed output data.",
            default => "Content for iteration {$iteration}",
        };

        // Add padding to simulate realistic message sizes
        // Each message should be substantial enough to matter for token counting
        $padding = str_repeat(" Token padding {$type}.", 50);

        return $baseContent . $padding;
    }

    private function assertToolPairsAreValid(array $messages): void
    {
        $toolCallIds = [];
        $toolResultIds = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getTools() as $tool) {
                    $toolCallIds[] = $tool->getCallId();
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getTools() as $tool) {
                    $toolResultIds[] = $tool->getCallId();
                }
            }
        }

        // Every tool result should have a corresponding tool call
        sort($toolCallIds);
        sort($toolResultIds);

        $this->assertEquals(
            count($toolCallIds),
            count($toolResultIds),
            'Tool call count should match tool result count'
        );

        foreach ($toolResultIds as $resultId) {
            $this->assertContains(
                $resultId,
                $toolCallIds,
                "Tool result with call_id '{$resultId}' has no matching tool call"
            );
        }
    }

    private function assertMessageAlternationIsValid(array $messages): void
    {
        $expectingUser = true;

        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                // After tool result, next regular message should be assistant
                $expectingUser = false;
                continue;
            }

            if ($message instanceof ToolCallMessage) {
                // After tool call, expect tool result (which doesn't change the alternation)
                continue;
            }

            $expectedRole = $expectingUser ? 'user' : 'assistant';
            $actualRole = $message->getRole();

            $this->assertEquals(
                $expectedRole,
                $actualRole,
                "Message at index {$index} has wrong role. Expected: {$expectedRole}, Got: {$actualRole}"
            );

            $expectingUser = !$expectingUser;
        }
    }
}
