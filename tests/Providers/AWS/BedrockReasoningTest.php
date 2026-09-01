<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\AWS\MessageMapper;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class BedrockReasoningTest extends TestCase
{
    public function test_stream_handles_reasoning_delta_union(): void
    {
        $events = [
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['reasoningContent' => ['text' => 'Let me']],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['reasoningContent' => ['text' => ' think']],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['reasoningContent' => ['signature' => 'sig-123']],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['reasoningContent' => ['redactedContent' => 'encrypted']],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 1,
                    'delta' => ['text' => 'The answer'],
                ],
            ],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => ['inputTokens' => 10, 'outputTokens' => 8]]],
        ];

        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseStream'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('converseStream')
            ->willReturn(new Result(['stream' => $this->eventIterator($events)]));

        $stream = (new BedrockRuntime($bedrockClient, 'model-reasoning'))
            ->stream(new UserMessage('Question?'));

        $chunks = iterator_to_array($stream);
        $message = $stream->getReturn()->message();

        $this->assertCount(3, $chunks);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[0]);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[1]);
        $this->assertInstanceOf(TextChunk::class, $chunks[2]);
        $this->assertSame('Let me', $chunks[0]->content);
        $this->assertSame(' think', $chunks[1]->content);
        $this->assertSame('The answer', $chunks[2]->content);

        $blocks = $message->getContentBlocks();

        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('Let me think', $blocks[0]->content);
        $this->assertSame('sig-123', $blocks[0]->id);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertSame('The answer', $blocks[1]->content);
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(8, $message->getUsage()->outputTokens);
    }

    public function test_chat_preserves_reasoning_for_next_request(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => ['inputTokens' => 10, 'outputTokens' => 8],
            'output' => [
                'message' => [
                    'content' => [
                        [
                            'reasoningContent' => [
                                'reasoningText' => [
                                    'text' => 'Let me think',
                                    'signature' => 'sig-123',
                                ],
                            ],
                        ],
                        [
                            'reasoningContent' => [
                                'redactedContent' => 'encrypted',
                            ],
                        ],
                        ['text' => 'The answer'],
                    ],
                ],
            ],
            'stopReason' => 'end_turn',
        ]);

        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->willReturn(new FulfilledPromise($result));

        $message = (new BedrockRuntime($bedrockClient, 'model-reasoning'))
            ->chat(new UserMessage('Question?'))
            ->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);

        $blocks = $message->getContentBlocks();

        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('Let me think', $blocks[0]->content);
        $this->assertSame('sig-123', $blocks[0]->id);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);

        $this->assertSame([
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'reasoningContent' => [
                            'reasoningText' => [
                                'text' => 'Let me think',
                                'signature' => 'sig-123',
                            ],
                        ],
                    ],
                    ['text' => 'The answer'],
                ],
            ],
        ], (new MessageMapper())->map([$message]));
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function eventIterator(array $events): EventParsingIterator
    {
        return new class ($events) extends EventParsingIterator {
            protected int $position = 0;

            /**
             * @param array<int, array<string, mixed>> $events
             */
            public function __construct(
                protected array $events,
            ) {
            }

            public function current(): mixed
            {
                return $this->events[$this->position];
            }

            public function key(): int
            {
                return $this->position;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->events[$this->position]);
            }
        };
    }
}
