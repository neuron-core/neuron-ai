<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Closure;
use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\ZAI\Audio\ZAITranscription;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_encode;
use function implode;
use function iterator_to_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Every chunk of a streamed response carries the ID of the message the stream
 * returns, so a UI reloading the stored message finds the ID it rendered live.
 */
class ProviderStreamContractTest extends TestCase
{
    #[DataProvider('recorded_streams')]
    public function test_chunks_carry_the_id_of_the_returned_message(Closure $provider, Message $input): void
    {
        [$chunks, $message] = $this->consume($provider()->stream($input));

        $this->assertNotSame([], $chunks);
        foreach ($chunks as $chunk) {
            $this->assertSame($message->getId(), $chunk->messageId);
        }

        // Replaying the same recording yields another identity: the ID never comes from the payload.
        [, $replayed] = $this->consume($provider()->stream($input));
        $this->assertNotSame($message->getId(), $replayed->getId());
    }

    public static function recorded_streams(): array
    {
        $tools = [new ToolStub('tool')];
        $question = new UserMessage('Question');

        $anthropicStart = ['type' => 'message_start', 'message' => ['id' => 'msg_vendor', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]];
        $anthropicText = self::sse([
            $anthropicStart,
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Answer']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ]);
        $anthropicToolCall = self::sse([
            $anthropicStart,
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_vendor', 'name' => 'tool', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":"Rome"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 1]],
        ]);

        $completionText = self::sse([
            ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Answer'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['content' => ''], 'finish_reason' => 'stop']]],
        ]);
        $completionToolCall = self::sse([
            ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_vendor', 'type' => 'function', 'function' => ['name' => 'tool', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"city":"Rome"}']]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);
        $mistralToolCall = self::sse([
            ['id' => 'cmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_vendor', 'function' => ['name' => 'tool', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['id' => 'cmpl-vendor', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"city":"Rome"}']]]], 'finish_reason' => 'tool_calls']]],
        ]);

        $responsesMessage = ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_vendor', 'content' => []]];
        $responsesDelta = ['type' => 'response.output_text.delta', 'item_id' => 'msg_vendor', 'delta' => 'Answer'];
        $responsesText = self::sse([
            $responsesMessage,
            $responsesDelta,
            ['type' => 'response.completed', 'response' => ['output' => [['type' => 'message', 'content' => [['text' => 'Answer']]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
        ]);
        $responsesToolCall = self::sse([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_vendor', 'call_id' => 'call_vendor', 'name' => 'tool', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_vendor', 'delta' => '{"city":"Rome"}'],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_vendor', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
        ]);
        $responsesWithoutCompletion = self::sse([$responsesMessage, $responsesDelta]);

        $cohereText = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Answer']]]],
            ['type' => 'message-end', 'usage' => ['tokens' => ['input_tokens' => 1, 'output_tokens' => 1]]],
        ]);
        $cohereToolCall = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'I will call the tool']]],
            ['type' => 'tool-call-start', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['id' => 'call_vendor', 'type' => 'function', 'function' => ['name' => 'tool', 'arguments' => '']]]]],
            ['type' => 'tool-call-delta', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => '{"city":"Rome"}']]]]],
            ['type' => 'tool-call-end', 'index' => 0],
        ]);

        $geminiText = json_encode([
            ['candidates' => [['content' => ['parts' => [['text' => 'Answer']]], 'finishReason' => 'STOP']]],
        ], JSON_THROW_ON_ERROR);
        $geminiToolCall = json_encode([
            ['candidates' => [['content' => ['parts' => [['text' => 'Checking']]]]]],
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'tool', 'args' => ['city' => 'Rome']]]]], 'finishReason' => 'STOP']]],
        ], JSON_THROW_ON_ERROR);

        $ollamaText = self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Answer'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 1, 'eval_count' => 1],
        ]);
        $ollamaToolCall = self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Checking'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'tool', 'arguments' => ['city' => 'Rome']]]]], 'done' => false],
        ]);

        $bedrockText = [
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Answer']]],
            ['messageStop' => ['stopReason' => 'end_turn']],
        ];
        $bedrockToolCall = [
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Checking']]],
            ['contentBlockStart' => ['contentBlockIndex' => 1, 'start' => ['toolUse' => ['toolUseId' => 'tooluse_vendor', 'name' => 'tool']]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 1, 'delta' => ['toolUse' => ['input' => '{"city":"Rome"}']]]],
            ['contentBlockStop' => ['contentBlockIndex' => 1]],
            ['messageStop' => ['stopReason' => 'tool_use']],
        ];

        $transcription = self::sse([
            ['type' => 'transcript.text.delta', 'delta' => 'Answer'],
            ['type' => 'transcript.text.done', 'text' => 'Answer'],
        ]);
        $audioFile = (new UserMessage('Transcribe'))->addContent(new AudioContent(__FILE__, SourceType::URL));
        $audioData = (new UserMessage('Transcribe'))->addContent(new AudioContent(base64_encode('audio'), SourceType::BASE64, 'audio/wav'));
        $speech = self::sse([
            ['type' => 'speech.audio.delta', 'audio' => base64_encode('audio')],
            ['type' => 'speech.audio.done', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
        ]);
        $image = self::sse([
            ['type' => 'image_generation.partial_image', 'partial_image_index' => 0, 'b64_json' => 'PARTIAL'],
            ['type' => 'image_generation.completed', 'b64_json' => 'FINAL'],
        ]);

        return [
            'anthropic text' => [static fn (): AIProviderInterface => new Anthropic('key', 'model', httpClient: self::client($anthropicText)), $question],
            'anthropic tool call' => [static fn (): AIProviderInterface => (new Anthropic('key', 'model', httpClient: self::client($anthropicToolCall)))->setTools($tools), $question],
            'openai text' => [static fn (): AIProviderInterface => new OpenAI('key', 'model', httpClient: self::client($completionText)), $question],
            'openai tool call' => [static fn (): AIProviderInterface => (new OpenAI('key', 'model', httpClient: self::client($completionToolCall)))->setTools($tools), $question],
            'openai responses text' => [static fn (): AIProviderInterface => new OpenAIResponses('key', 'model', httpClient: self::client($responsesText)), $question],
            'openai responses tool call' => [static fn (): AIProviderInterface => (new OpenAIResponses('key', 'model', httpClient: self::client($responsesToolCall)))->setTools($tools), $question],
            'openai responses without completion' => [static fn (): AIProviderInterface => new OpenAIResponses('key', 'model', httpClient: self::client($responsesWithoutCompletion)), $question],
            'cohere text' => [static fn (): AIProviderInterface => new Cohere('key', 'model', httpClient: self::client($cohereText)), $question],
            'cohere tool call' => [static fn (): AIProviderInterface => (new Cohere('key', 'model', httpClient: self::client($cohereToolCall)))->setTools($tools), $question],
            'gemini text' => [static fn (): AIProviderInterface => new Gemini('key', 'model', httpClient: self::client($geminiText)), $question],
            'gemini tool call' => [static fn (): AIProviderInterface => (new Gemini('key', 'model', httpClient: self::client($geminiToolCall)))->setTools($tools), $question],
            'mistral text' => [static fn (): AIProviderInterface => new Mistral('key', 'model', httpClient: self::client($completionText)), $question],
            'mistral tool call' => [static fn (): AIProviderInterface => (new Mistral('key', 'model', httpClient: self::client($mistralToolCall)))->setTools($tools), $question],
            'ollama text' => [static fn (): AIProviderInterface => new Ollama('http://localhost/api', 'model', httpClient: self::client($ollamaText)), $question],
            'ollama tool call' => [static fn (): AIProviderInterface => (new Ollama('http://localhost/api', 'model', httpClient: self::client($ollamaToolCall)))->setTools($tools), $question],
            'bedrock text' => [static fn (): AIProviderInterface => new BedrockRuntime(new BedrockStreamClient($bedrockText), 'model'), $question],
            'bedrock tool call' => [static fn (): AIProviderInterface => (new BedrockRuntime(new BedrockStreamClient($bedrockToolCall), 'model'))->setTools($tools), $question],
            'openai speech to text' => [static fn (): AIProviderInterface => new OpenAISpeechToText('key', 'model', httpClient: self::client($transcription)), $audioFile],
            'zai transcription' => [static fn (): AIProviderInterface => new ZAITranscription('key', 'model', httpClient: self::client($transcription)), $audioData],
            'openai text to speech' => [static fn (): AIProviderInterface => new OpenAITextToSpeech('key', 'model', 'alloy', httpClient: self::client($speech)), $question],
            'elevenlabs text to speech' => [static fn (): AIProviderInterface => new ElevenLabsTextToSpeech('key', 'model', 'voice', httpClient: self::client('audio')), $question],
            'openai image' => [static fn (): AIProviderInterface => new OpenAIImage('key', 'model', httpClient: self::client($image)), $question],
            'fake text' => [static fn (): AIProviderInterface => new FakeAIProvider(new AssistantMessage('Answer')), $question],
            'fake tool call' => [static fn (): AIProviderInterface => new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('tool', 'call_fake', ['city' => 'Rome'])])), $question],
        ];
    }

    /**
     * @param Generator<int, StreamChunk, mixed, ProviderResponse> $stream
     * @return array{0: StreamChunk[], 1: Message}
     */
    protected function consume(Generator $stream): array
    {
        $chunks = iterator_to_array($stream, false);

        return [$chunks, $stream->getReturn()->message()];
    }

    protected static function client(string $body): GuzzleHttpClient
    {
        return new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])));
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected static function sse(array $events): string
    {
        return implode('', array_map(
            static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    protected static function ndjson(array $lines): string
    {
        return implode("\n", array_map(
            static fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR),
            $lines,
        ))."\n";
    }
}
