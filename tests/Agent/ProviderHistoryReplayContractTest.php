<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function glob;
use function implode;
use function is_dir;
use function is_file;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * A conversation leaves a real provider's response parser, is written to a
 * message store, is reloaded by another process and is mapped back to the
 * vendor's wire format. The model must see the same conversation whether the
 * messages come from the live tool loop or from any store: attachments, tool
 * calls and their results, reasoning signatures and redacted thinking the
 * vendor requires to be echoed verbatim, and the usage it reported.
 */
class ProviderHistoryReplayContractTest extends TestCase
{
    use RecordsHttpRequests;

    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected const PDF = 'JVBERi0xLjQKJcOkw7zDtsOfCg==';

    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/neuron_replay_contract_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        if (is_file($this->directory.'.sqlite')) {
            unlink($this->directory.'.sqlite');
        }
    }

    /**
     * Each factory prepares a store backend and returns how a new process
     * connects to it.
     *
     * @return array<string, array{Closure(string): (Closure(): MessageStoreInterface)}>
     */
    public static function stores(): array
    {
        return [
            'in-memory' => [static function (string $directory): Closure {
                $store = new InMemoryMessageStore();

                return static fn (): MessageStoreInterface => $store;
            }],
            'file' => [static fn (string $directory): Closure => static fn (): MessageStoreInterface => new FileMessageStore($directory)],
            'sql' => [static function (string $directory): Closure {
                (new PDO('sqlite:'.$directory.'.sqlite'))->exec(SqliteMessageStore::SCHEMA);

                return static fn (): MessageStoreInterface => new SQLMessageStore(new PDO('sqlite:'.$directory.'.sqlite'));
            }],
            'eloquent' => [static function (string $directory): Closure {
                $capsule = new Capsule();
                $capsule->addConnection(['driver' => 'sqlite', 'database' => $directory.'.sqlite']);
                $capsule->setAsGlobal();
                $capsule->bootEloquent();
                (new PDO('sqlite:'.$directory.'.sqlite'))->exec(SqliteMessageStore::SCHEMA);

                return static fn (): MessageStoreInterface => new EloquentMessageStore(ChatMessage::class);
            }],
        ];
    }

    /**
     * The vendor's tool-call and final responses, where the conversation sits
     * in the request body, and the exact conversation the vendor must receive
     * on the second turn.
     *
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, array<string, mixed>, array<string, mixed>, string, list<array<string, mixed>>, Usage}>
     */
    public static function providers(): array
    {
        return [
            'anthropic' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic('key', 'claude', httpClient: $client),
                ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
                    ['type' => 'thinking', 'thinking' => 'The user wants PHP results.', 'signature' => 'sig-thinking-1'],
                    ['type' => 'redacted_thinking', 'data' => 'opaque-redacted-1'],
                    ['type' => 'text', 'text' => 'Let me search.'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => ['query' => 'php']],
                ], 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]],
                ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                    ['type' => 'text', 'text' => 'Here is the chart summary.'],
                ], 'usage' => ['input_tokens' => 200, 'output_tokens' => 12, 'cache_read_input_tokens' => 64]],
                'messages',
                [
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Describe this chart'],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]],
                        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => self::PDF]],
                    ]],
                    ['role' => 'assistant', 'content' => [
                        ['type' => 'thinking', 'thinking' => 'The user wants PHP results.', 'signature' => 'sig-thinking-1'],
                        ['type' => 'redacted_thinking', 'data' => 'opaque-redacted-1'],
                        ['type' => 'text', 'text' => 'Let me search.'],
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => ['query' => 'php']],
                    ]],
                    ['role' => 'user', 'content' => [
                        ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'Results for: php'],
                    ]],
                    ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Here is the chart summary.']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And the trend?']]],
                ],
                new Usage(200, 12, 64),
            ],
            'openai' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI('key', 'gpt', httpClient: $client),
                ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"query":"php"}']]],
                ]]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150]],
                ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
                    'role' => 'assistant',
                    'content' => 'Here is the chart summary.',
                ]]], 'usage' => [
                    'prompt_tokens' => 200,
                    'completion_tokens' => 12,
                    'total_tokens' => 212,
                    'prompt_tokens_details' => ['cached_tokens' => 64],
                    'completion_tokens_details' => ['reasoning_tokens' => 5],
                ]],
                'messages',
                [
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Describe this chart'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PNG]],
                        ['type' => 'file', 'file' => ['filename' => 'chart.pdf', 'file_data' => 'data:application/pdf;base64,'.self::PDF]],
                    ]],
                    ['role' => 'assistant', 'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"query":"php"}']],
                    ]],
                    ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Results for: php'],
                    ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Here is the chart summary.']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'And the trend?']]],
                ],
                new Usage(200, 12, 64, 5),
            ],
            'openai-responses' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAIResponses('key', 'gpt', httpClient: $client),
                ['id' => 'resp_1', 'status' => 'completed', 'output' => [
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'search', 'arguments' => '{"query":"php"}'],
                ], 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]],
                ['id' => 'resp_2', 'status' => 'completed', 'output' => [
                    ['type' => 'message', 'id' => 'msg_2', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => 'Here is the chart summary.', 'annotations' => []],
                    ]],
                ], 'usage' => [
                    'input_tokens' => 200,
                    'output_tokens' => 12,
                    'input_tokens_details' => ['cached_tokens' => 64],
                    'output_tokens_details' => ['reasoning_tokens' => 5],
                ]],
                'input',
                [
                    ['role' => 'user', 'content' => [
                        ['type' => 'input_text', 'text' => 'Describe this chart'],
                        ['type' => 'input_image', 'image_url' => 'data:image/png;base64,'.self::PNG],
                        ['type' => 'input_file', 'filename' => 'chart.pdf', 'file_data' => 'data:application/pdf;base64,'.self::PDF],
                    ]],
                    ['type' => 'function_call', 'name' => 'search', 'arguments' => '{"query":"php"}', 'call_id' => 'call_1'],
                    ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'Results for: php'],
                    ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Here is the chart summary.']]],
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'And the trend?']]],
                ],
                new Usage(200, 12, 64, 5),
            ],
            'gemini' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Gemini('key', 'gemini', httpClient: $client),
                ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Let me search.', 'thoughtSignature' => 'sig-text-1'],
                    ['functionCall' => ['name' => 'search', 'args' => ['query' => 'php']], 'thoughtSignature' => 'sig-call-1'],
                ]]]], 'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30]],
                ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Here is the chart summary.'],
                ]]]], 'usageMetadata' => [
                    'promptTokenCount' => 200,
                    'candidatesTokenCount' => 12,
                    'cachedContentTokenCount' => 64,
                    'thoughtsTokenCount' => 5,
                ]],
                'contents',
                [
                    ['role' => 'user', 'parts' => [
                        ['text' => 'Describe this chart'],
                        ['inline_data' => ['data' => self::PNG, 'mime_type' => 'image/png']],
                        ['inline_data' => ['data' => self::PDF, 'mime_type' => 'application/pdf']],
                    ]],
                    ['role' => 'model', 'parts' => [
                        ['text' => 'Let me search.', 'thought_signature' => 'sig-text-1'],
                        ['functionCall' => ['name' => 'search', 'args' => ['query' => 'php']], 'thought_signature' => 'sig-call-1'],
                    ]],
                    ['role' => 'user', 'parts' => [
                        ['functionResponse' => ['name' => 'search', 'response' => [
                            'name' => 'search',
                            'content' => 'Results for: php',
                        ]]],
                    ]],
                    ['role' => 'model', 'parts' => [['text' => 'Here is the chart summary.']]],
                    ['role' => 'user', 'parts' => [['text' => 'And the trend?']]],
                ],
                new Usage(200, 12, 64, 5),
            ],
        ];
    }

    /**
     * @return array<string, array{Closure, array<string, mixed>, array<string, mixed>, string, list<array<string, mixed>>, Usage, Closure}>
     */
    public static function providersAndStores(): array
    {
        $cases = [];
        foreach (self::providers() as $provider => $providerCase) {
            foreach (self::stores() as $store => $storeCase) {
                $cases["{$provider} over {$store}"] = [...$providerCase, ...$storeCase];
            }
        }

        return $cases;
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $makeProvider
     * @param array<string, mixed> $toolCallResponse
     * @param array<string, mixed> $answerResponse
     * @param list<array<string, mixed>> $conversation
     * @param Closure(string): (Closure(): MessageStoreInterface) $prepareStore
     */
    #[DataProvider('providersAndStores')]
    public function test_a_reloaded_conversation_reaches_the_vendor_exactly_as_the_live_one(
        Closure $makeProvider,
        array $toolCallResponse,
        array $answerResponse,
        string $conversationKey,
        array $conversation,
        Usage $answerUsage,
        Closure $prepareStore,
    ): void {
        $connectToStore = $prepareStore($this->directory);
        $client = $this->recordingClient(
            $this->jsonResponse($toolCallResponse),
            $this->jsonResponse($answerResponse),
            $this->jsonResponse($answerResponse),
        );

        $this->agent($makeProvider($client), $connectToStore())->chat($this->question());

        // Another process: every collaborator is rebuilt, only the store's data survives.
        $state = $this->agent($makeProvider($client), $connectToStore())->chat(new UserMessage('And the trend?'));

        $this->assertSame('Here is the chart summary.', $state->getMessage()?->getContent());
        $this->assertCount(3, $this->sentRequests);
        $this->assertEquals(array_slice($conversation, 0, 1), $this->sentConversation(0, $conversationKey), 'First inference');
        $this->assertEquals(array_slice($conversation, 0, 3), $this->sentConversation(1, $conversationKey), 'Live tool loop');
        $this->assertEquals($conversation, $this->sentConversation(2, $conversationKey), 'Reloaded conversation');

        $stored = $connectToStore()->loadAll('replay-thread');
        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant', 'user', 'assistant'],
            array_map(static fn (Message $message): string => $message->getRole(), $stored),
        );
        $this->assertEquals($answerUsage, $stored[3]->getUsage(), 'The usage the vendor reported survives the store.');
    }

    /**
     * The same exchanges streamed as server-sent events.
     *
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, string, string, string, list<array<string, mixed>>}>
     */
    public static function streams(): array
    {
        $providers = self::providers();

        return [
            'anthropic' => [
                $providers['anthropic'][0],
                self::sse(
                    ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 120, 'output_tokens' => 1]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'The user wants ']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'PHP results.']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-thinking-1']],
                    ['type' => 'content_block_stop', 'index' => 0],
                    ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'opaque-redacted-1']],
                    ['type' => 'content_block_stop', 'index' => 1],
                    ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'Let me search.']],
                    ['type' => 'content_block_stop', 'index' => 2],
                    ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search', 'input' => []]],
                    ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":']],
                    ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"php"}']],
                    ['type' => 'content_block_stop', 'index' => 3],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 30]],
                    ['type' => 'message_stop'],
                ),
                self::sse(
                    ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'usage' => ['input_tokens' => 200, 'output_tokens' => 1]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Here is the chart summary.']],
                    ['type' => 'content_block_stop', 'index' => 0],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]],
                    ['type' => 'message_stop'],
                ),
                'messages',
                $providers['anthropic'][4],
            ],
            'openai' => [
                $providers['openai'][0],
                self::sse(
                    ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [
                        ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"query":']],
                    ]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                        ['index' => 0, 'function' => ['arguments' => '"php"}']],
                    ]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
                ).'data: [DONE]'."\n\n",
                self::sse(
                    ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Here is the chart summary.'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-2', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
                ).'data: [DONE]'."\n\n",
                'messages',
                $providers['openai'][4],
            ],
        ];
    }

    /**
     * @param array<string, mixed> ...$events
     */
    protected static function sse(array ...$events): string
    {
        return implode('', array_map(
            static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ));
    }

    /**
     * @return array<string, array{Closure, string, string, string, list<array<string, mixed>>, Closure}>
     */
    public static function streamsAndStores(): array
    {
        $cases = [];
        foreach (self::streams() as $provider => $streamCase) {
            foreach (self::stores() as $store => $storeCase) {
                $cases["{$provider} stream over {$store}"] = [...$streamCase, ...$storeCase];
            }
        }

        return $cases;
    }

    /**
     * A message assembled from stream deltas is stored and replayed exactly
     * like the one parsed from a complete response.
     *
     * @param Closure(HttpClientInterface): AIProviderInterface $makeProvider
     * @param list<array<string, mixed>> $conversation
     * @param Closure(string): (Closure(): MessageStoreInterface) $prepareStore
     */
    #[DataProvider('streamsAndStores')]
    public function test_a_streamed_conversation_is_stored_and_replayed_like_a_complete_one(
        Closure $makeProvider,
        string $toolCallStream,
        string $answerStream,
        string $conversationKey,
        array $conversation,
        Closure $prepareStore,
    ): void {
        $connectToStore = $prepareStore($this->directory);
        $client = $this->recordingClient(
            new Response(200, ['Content-Type' => 'text/event-stream'], $toolCallStream),
            new Response(200, ['Content-Type' => 'text/event-stream'], $answerStream),
            new Response(200, ['Content-Type' => 'text/event-stream'], $answerStream),
        );

        foreach ([$this->question(), new UserMessage('And the trend?')] as $message) {
            $stream = $this->agent($makeProvider($client), $connectToStore())->stream($message);
            iterator_to_array($stream, false);
            $this->assertSame('Here is the chart summary.', $stream->getReturn()->getMessage()?->getContent());
        }

        $this->assertCount(3, $this->sentRequests);
        $this->assertEquals(array_slice($conversation, 0, 3), $this->sentConversation(1, $conversationKey), 'Live tool loop');
        $this->assertEquals($conversation, $this->sentConversation(2, $conversationKey), 'Reloaded conversation');
    }

    /**
     * Tool results that are more than text, an image and a handled failure,
     * keep their blocks and their error flag through every store.
     *
     * @param Closure(string): (Closure(): MessageStoreInterface) $prepareStore
     */
    #[DataProvider('stores')]
    public function test_multimodal_and_error_tool_results_are_replayed_from_every_store(Closure $prepareStore): void
    {
        $connectToStore = $prepareStore($this->directory);
        $client = $this->recordingClient(
            $this->jsonResponse(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'render', 'input' => ['chart' => 'sales']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'render', 'input' => ['chart' => 'costs']],
            ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
            $this->jsonResponse(['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Sales rendered, costs failed.'],
            ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
            $this->jsonResponse(['id' => 'msg_3', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'Retry later.'],
            ], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
        );
        $renderer = new class (self::PNG) extends Tool {
            protected string $name = 'render';

            protected ?string $description = 'Render a chart';

            public function __construct(protected string $png)
            {
            }

            protected function properties(): array
            {
                return [new ToolProperty('chart', PropertyType::STRING, 'Chart name', true)];
            }

            public function __invoke(string $chart): ToolOutput
            {
                return $chart === 'sales'
                    ? new ToolOutput([new TextContent('Rendered'), new ImageContent($this->png, SourceType::BASE64, 'image/png')])
                    : ToolOutput::error('Quota exceeded');
            }
        };

        foreach ([new UserMessage('Render sales and costs'), new UserMessage('And now?')] as $message) {
            Agent::make(workflowId: 'replay-thread')
                ->setAiProvider(new Anthropic('key', 'claude', httpClient: $client))
                ->setMessageStore($connectToStore())
                ->addTool($renderer)
                ->chat($message);
        }

        $toolResults = ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => [
                ['type' => 'text', 'text' => 'Rendered'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]],
            ]],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => [['type' => 'text', 'text' => 'Quota exceeded']], 'is_error' => true],
        ]];
        $this->assertEquals($toolResults, $this->sentConversation(1, 'messages')[2], 'Live tool loop');
        $this->assertEquals($toolResults, $this->sentConversation(2, 'messages')[2], 'Reloaded conversation');
    }

    protected function question(): UserMessage
    {
        return new UserMessage([
            new TextContent('Describe this chart'),
            new ImageContent(self::PNG, SourceType::BASE64, 'image/png'),
            new FileContent(self::PDF, SourceType::BASE64, 'application/pdf', 'chart.pdf'),
        ]);
    }

    protected function agent(AIProviderInterface $provider, MessageStoreInterface $store): Agent
    {
        return Agent::make(workflowId: 'replay-thread')
            ->setAiProvider($provider)
            ->setMessageStore($store)
            ->addTool(new SearchTool());
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function jsonResponse(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sentConversation(int $request, string $key): array
    {
        $body = json_decode((string) $this->sentRequests[$request]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);

        // Some vendors carry the instructions inside the conversation; they are not history.
        return array_values(array_filter(
            $body[$key],
            static fn (array $entry): bool => ($entry['role'] ?? null) !== 'system',
        ));
    }
}
