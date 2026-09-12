<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\AWS\MessageMapper;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_splice;
use function count;
use function iterator_to_array;
use function json_encode;
use function str_split;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;

class BedrockRedactedReasoningTest extends TestCase
{
    #[DataProvider('responseModes')]
    public function test_redacted_reasoning_round_trip(bool $streaming, bool $toolUse, bool $redactedOnly): void
    {
        $contents = $redactedOnly ? [
            ['reasoningContent' => ['redactedContent' => "\xff\x00\x80"]],
        ] : [
            ['reasoningContent' => ['redactedContent' => "\xff\x00\x80"]],
            ['reasoningContent' => ['reasoningText' => ['text' => 'First thought', 'signature' => 'sig-1']]],
            ['reasoningContent' => ['redactedContent' => "\xfe\x81"]],
            ['reasoningContent' => ['redactedContent' => "\xfd\x82"]],
            ['text' => 'Hello'],
            ['text' => ' world'],
            ['reasoningContent' => ['redactedContent' => "\xfc\x83"]],
            ['text' => '!'],
            ['reasoningContent' => ['reasoningText' => ['text' => 'Second thought', 'signature' => 'sig-2']]],
            ['reasoningContent' => ['redactedContent' => "\xfb\x84"]],
        ];

        if ($toolUse) {
            array_splice($contents, 3, 0, [
                ['toolUse' => ['name' => 'lookup', 'input' => ['query' => 'first'], 'toolUseId' => 'call-1']],
            ]);
            array_splice($contents, 9, 0, [
                ['toolUse' => ['name' => 'lookup', 'input' => ['query' => 'second'], 'toolUseId' => 'call-2']],
            ]);
        }

        $client = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync', 'converseStream'])
            ->getMock();

        $provider = (new BedrockRuntime($client, 'model-reasoning'))->setTools([new Tool('lookup')]);
        $stopReason = $toolUse ? 'tool_use' : 'end_turn';

        if ($streaming) {
            $events = $this->streamEvents($contents);
            $events[] = ['messageStop' => ['stopReason' => $stopReason]];
            $iterator = $this->createMock(EventParsingIterator::class);
            $iterator->method('valid')->willReturnOnConsecutiveCalls(...[...array_fill(0, count($events), true), false]);
            $iterator->method('current')->willReturnOnConsecutiveCalls(...$events);
            $client->expects($this->once())->method('converseStream')
                ->willReturn(new Result(['stream' => $iterator]));

            $stream = $provider->stream(new UserMessage('Question?'));
            $chunks = iterator_to_array($stream, false);
            $message = $stream->getReturn();
            $this->assertCount($redactedOnly ? 0 : 7, $chunks);
        } else {
            $client->expects($this->once())->method('converseAsync')
                ->willReturn(new FulfilledPromise(new Result([
                    'output' => ['message' => ['content' => $contents]],
                    'stopReason' => $stopReason,
                ])));
            $message = $provider->chat(new UserMessage('Question?'));
        }

        $this->assertSame($toolUse, $message instanceof ToolCallMessage);
        $this->assertCount($redactedOnly ? 0 : 5, $message->getContentBlocks());
        $this->assertSame($redactedOnly ? null : 'Hello  world !', $message->getContent());
        $this->assertSame($redactedOnly ? null : 'First thought', $message->getReasoning()?->content);

        $mapper = new MessageMapper();
        $this->assertSame($contents, $mapper->map([$message])[0]['content']);
        $key = uniqid('redacted_reasoning_', true);
        $history = new FileChatHistory(sys_get_temp_dir(), $key);
        try {
            $history->addMessage(new UserMessage('Question?'));
            $history->addMessage($message);
            $restored = (new FileChatHistory(sys_get_temp_dir(), $key))->getMessages();
            $this->assertSame($contents, $mapper->map($restored)[1]['content']);
        } finally {
            $history->flushAll();
        }
    }

    public static function responseModes(): array
    {
        return [
            'chat assistant' => [false, false, false],
            'chat tools' => [false, true, false],
            'chat redacted only' => [false, false, true],
            'stream assistant' => [true, false, false],
            'stream tools' => [true, true, false],
            'stream redacted only' => [true, false, true],
        ];
    }

    /**
     * @param list<array<string, mixed>> $contents
     * @return list<array<string, mixed>>
     */
    protected function streamEvents(array $contents): array
    {
        $events = [];
        foreach ($contents as $index => $content) {
            $deltas = [];
            if (isset($content['text'])) {
                $deltas[] = ['text' => $content['text']];
            } elseif (isset($content['reasoningContent']['reasoningText'])) {
                $reasoning = $content['reasoningContent']['reasoningText'];
                $deltas[] = ['reasoningContent' => ['text' => substr($reasoning['text'], 0, 2)]];
                $deltas[] = ['reasoningContent' => ['text' => substr($reasoning['text'], 2)]];
                $deltas[] = ['reasoningContent' => ['signature' => $reasoning['signature']]];
            } elseif (isset($content['reasoningContent']['redactedContent'])) {
                foreach (str_split($content['reasoningContent']['redactedContent']) as $byte) {
                    $deltas[] = ['reasoningContent' => ['redactedContent' => $byte]];
                }
            } else {
                $tool = $content['toolUse'];
                $events[] = ['contentBlockStart' => [
                    'contentBlockIndex' => $index,
                    'start' => ['toolUse' => ['name' => $tool['name'], 'toolUseId' => $tool['toolUseId']]],
                ]];
                $deltas[] = ['toolUse' => ['input' => json_encode($tool['input'], JSON_THROW_ON_ERROR)]];
            }

            foreach ($deltas as $delta) {
                $events[] = ['contentBlockDelta' => ['contentBlockIndex' => $index, 'delta' => $delta]];
            }
            $events[] = ['contentBlockStop' => ['contentBlockIndex' => $index]];
        }
        return $events;
    }
}
