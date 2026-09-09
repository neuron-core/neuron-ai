<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenAIResponsesReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_completions')]
    public function test_reasoning_deltas_preserve_each_completion_path(array $fragments, array $expected, string $completion): void
    {
        $events = [['type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs-test', 'part' => ['text' => '']]];
        foreach ($fragments as $fragment) {
            $events[] = ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs-test', 'delta' => $fragment];
        }
        if ($completion === 'tools') {
            $events[] = ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc-test', 'call_id' => 'call-test', 'name' => 'lookup', 'arguments' => '']];
            $events[] = ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc-test', 'delta' => '{"query":"test"}'];
            $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc-test', 'arguments' => '{"query":"test"}'];
        }
        if ($completion !== 'eof') {
            $events[] = ['type' => 'response.completed', 'response' => [
                'output' => [['type' => 'reasoning', 'id' => 'rs-test', 'summary' => [['type' => 'summary_text', 'text' => implode('', $fragments)]]]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
            ]];
        }
        $provider = new OpenAIResponses('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        $provider->setTools([new ToolStub('lookup')]);
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        foreach (array_slice($chunks, 0, count($expected)) as $chunk) {
            $this->assertSame('rs-test', $chunk->messageId);
        }
        if ($completion === 'tools') {
            $this->assertInstanceOf(ToolCallMessage::class, $message);
            $this->assertSame(['query' => 'test'], $message->getToolCalls()[0]->getInputs());
            $this->assertCount(count($expected) + 1, $chunks);
        } elseif ($completion === 'assistant') {
            $this->assertSame('rs-test', $message->getReasoning()->id);
        }
        if ($completion !== 'eof') {
            $this->assertSame(3, $message->getUsage()->inputTokens);
            $this->assertSame(4, $message->getUsage()->outputTokens);
        }
    }

    public static function reasoning_completions(): array
    {
        $cases = [];
        foreach (self::reasoning_sequences() as $name => $sequence) {
            foreach (['assistant', 'tools', 'eof'] as $completion) {
                $cases[$name.' '.$completion] = [...$sequence, $completion];
            }
        }
        return $cases;
    }

    #[DataProvider('reasoning_sequences')]
    public function test_part_added_emits_its_initial_text(array $fragments, array $expected): void
    {
        foreach ($fragments as $fragment) {
            $events = [['type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs-test', 'part' => ['text' => $fragment]]];
            $provider = new OpenAIResponses('test', 'model', httpClient: $this->streamClient($this->sse($events)));
            $provider->setTools([new ToolStub('lookup')]);
            [, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $fragment === '' ? [] : [$fragment]);
            $this->assertSame($fragment, $message->getReasoning()?->content);
        }
    }

    public function test_missing_text_defaults_preserve_reasoning_block(): void
    {
        $events = [
            ['type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs-test', 'part' => []],
            ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs-test'],
        ];
        $provider = new OpenAIResponses('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        $provider->setTools([new ToolStub('lookup')]);
        [, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), []);
        $this->assertSame('', $message->getReasoning()?->content);
    }
}
