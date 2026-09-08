<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Anthropic\MessageMapper;
use NeuronAI\Tests\Chat\History\Stub\TestableChatHistory;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_splice;
use function implode;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function substr;

use const JSON_THROW_ON_ERROR;

class AnthropicRedactedReasoningTest extends TestCase
{
    #[DataProvider('responseModes')]
    public function test_redacted_reasoning_round_trip(bool $streaming, bool $toolUse, bool $redactedOnly): void
    {
        $contents = $redactedOnly ? [
            ['type' => 'redacted_thinking', 'data' => '/wCA'],
        ] : [
            ['type' => 'redacted_thinking', 'data' => '/wCA'],
            ['type' => 'thinking', 'thinking' => 'First thought', 'signature' => 'sig-1'],
            ['type' => 'redacted_thinking', 'data' => '/oE='],
            ['type' => 'redacted_thinking', 'data' => '/YI='],
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => ' world'],
            ['type' => 'redacted_thinking', 'data' => '/IM='],
            ['type' => 'text', 'text' => '!'],
            ['type' => 'thinking', 'thinking' => 'Second thought', 'signature' => 'sig-2'],
            ['type' => 'redacted_thinking', 'data' => '+4Q='],
        ];

        if ($toolUse) {
            array_splice($contents, 3, 0, [
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'lookup', 'input' => ['query' => 'first']],
            ]);
            array_splice($contents, 9, 0, [
                ['type' => 'tool_use', 'id' => 'call-2', 'name' => 'lookup', 'input' => ['query' => 'second']],
            ]);
        }

        $body = $streaming ? $this->streamBody($contents) : json_encode([
            'content' => $contents,
            'stop_reason' => $toolUse ? 'tool_use' : 'end_turn',
        ], JSON_THROW_ON_ERROR);
        $stack = HandlerStack::create(new MockHandler([new Response(200, body: $body)]));
        $provider = (new Anthropic('', 'model-reasoning'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack))
            ->setTools([new ToolStub('lookup')]);

        if ($streaming) {
            $stream = $provider->stream(new UserMessage('Question?'));
            $chunks = iterator_to_array($stream, false);
            $message = $stream->getReturn()->message();
            $this->assertCount($redactedOnly ? 0 : ($toolUse ? 9 : 7), $chunks);
        } else {
            $message = $provider->chat(new UserMessage('Question?'))->message();
        }

        $this->assertSame($toolUse, $message instanceof ToolCallMessage);
        $this->assertCount($redactedOnly ? 0 : 5, $message->getContentBlocks());
        $this->assertSame($redactedOnly ? null : 'Hello  world !', $message->getContent());
        $this->assertSame($redactedOnly ? null : 'First thought', $message->getReasoning()?->content);

        $mapper = new MessageMapper();
        $this->assertSame($contents, $mapper->map([$message])[0]['content']);
        $serialized = json_encode([$message], JSON_THROW_ON_ERROR);
        $restored = (new TestableChatHistory())->publicDeserialize(json_decode($serialized, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($contents, $mapper->map($restored)[0]['content']);
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

    protected function streamBody(array $contents): string
    {
        $events = [['type' => 'message_start', 'message' => ['id' => 'msg-test', 'usage' => []]]];
        foreach ($contents as $index => $content) {
            $start = $content;
            $deltas = [];
            if ($content['type'] === 'text') {
                $start['text'] = '';
                $deltas[] = ['type' => 'text_delta', 'text' => $content['text']];
            } elseif ($content['type'] === 'thinking') {
                $start['thinking'] = '';
                unset($start['signature']);
                $deltas[] = ['type' => 'thinking_delta', 'thinking' => substr($content['thinking'], 0, 2)];
                $deltas[] = ['type' => 'thinking_delta', 'thinking' => substr($content['thinking'], 2)];
                $deltas[] = ['type' => 'signature_delta', 'signature' => $content['signature']];
            } elseif ($content['type'] === 'tool_use') {
                $start['input'] = [];
                $deltas[] = ['type' => 'input_json_delta', 'partial_json' => json_encode($content['input'], JSON_THROW_ON_ERROR)];
            }
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start];
            foreach ($deltas as $delta) {
                $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $index];
        }
        $events[] = ['type' => 'message_stop'];

        return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
    }
}
