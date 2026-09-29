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
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
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
 * A stream is an answer only once its closing event arrives: a cut stream fails,
 * and one the application stopped keeps the text streamed so far.
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

        $cohereText = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Answer']]]],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 1, 'output_tokens' => 1]]]],
        ]);
        $cohereToolCall = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'I will call the tool']]],
            ['type' => 'tool-call-start', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['id' => 'call_vendor', 'type' => 'function', 'function' => ['name' => 'tool', 'arguments' => '']]]]],
            ['type' => 'tool-call-delta', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => '{"city":"Rome"}']]]]],
            ['type' => 'tool-call-end', 'index' => 0],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'TOOL_CALL']],
        ]);

        $geminiText = self::sse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Answer']]], 'finishReason' => 'STOP']]],
        ]);
        $geminiToolCall = self::sse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Checking']]]]]],
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'tool', 'args' => ['city' => 'Rome']]]]], 'finishReason' => 'STOP']]],
        ]);

        $ollamaText = self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Answer'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 1, 'eval_count' => 1],
        ]);
        $ollamaToolCall = self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Checking'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'tool', 'arguments' => ['city' => 'Rome']]]]], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop'],
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
     * A proxy, a load balancer or the vendor's edge can cut the connection mid-answer:
     * the half answer must fail the call, never pass for a complete one.
     */
    #[DataProvider('cut_streams')]
    public function test_a_stream_cut_before_its_closing_event_is_refused(Closure $provider): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The stream ended before the answer was complete.');

        $this->consume($provider()->stream(new UserMessage('Question')));
    }

    public static function cut_streams(): array
    {
        $anthropic = self::sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_vendor', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Your refund of $1']],
        ]);
        $completion = self::sse([['choices' => [['index' => 0, 'delta' => ['content' => 'Your refund of $1'], 'finish_reason' => null]]]]);
        $responses = self::sse([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_vendor', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_vendor', 'delta' => 'Your refund of $1'],
        ]);
        $cohere = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Your refund of $1']]]],
        ]);
        $gemini = self::sse([['candidates' => [['content' => ['parts' => [['text' => 'Your refund of $1']]]]]]]);
        $ollama = self::ndjson([['message' => ['role' => 'assistant', 'content' => 'Your refund of $1'], 'done' => false]]);
        $bedrock = [['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Your refund of $1']]]];

        return [
            'anthropic' => [static fn (): AIProviderInterface => new Anthropic('key', 'model', httpClient: self::client($anthropic))],
            'openai' => [static fn (): AIProviderInterface => new OpenAI('key', 'model', httpClient: self::client($completion))],
            'openai responses' => [static fn (): AIProviderInterface => new OpenAIResponses('key', 'model', httpClient: self::client($responses))],
            'cohere' => [static fn (): AIProviderInterface => new Cohere('key', 'model', httpClient: self::client($cohere))],
            'gemini' => [static fn (): AIProviderInterface => new Gemini('key', 'model', httpClient: self::client($gemini))],
            'mistral' => [static fn (): AIProviderInterface => new Mistral('key', 'model', httpClient: self::client($completion))],
            'ollama' => [static fn (): AIProviderInterface => new Ollama('http://localhost/api', 'model', httpClient: self::client($ollama))],
            'bedrock' => [static fn (): AIProviderInterface => new BedrockRuntime(new BedrockStreamClient($bedrock), 'model')],
        ];
    }

    /**
     * A "stop generating" button: the answer keeps the text the user saw, marked stopped.
     *
     * @param Closure(Closure(): bool): AIProviderInterface $provider
     */
    #[DataProvider('stoppable_streams')]
    public function test_a_stopped_stream_keeps_the_text_streamed_so_far(Closure $provider): void
    {
        $stopped = false;
        $answer = $provider(function () use (&$stopped): bool {
            return $stopped;
        })->stream(new UserMessage('Question'));

        $chunks = [];
        foreach ($answer as $chunk) {
            $chunks[] = $chunk;
            $stopped = true; // the user clicks "Stop" after the first words
        }
        $message = $answer->getReturn()->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Hel', $message->getContent());
        $this->assertSame(StoppableHttpClient::STOP_REASON, $message->stopReason());
        foreach ($chunks as $chunk) {
            $this->assertSame($message->getId(), $chunk->messageId);
        }
    }

    public static function stoppable_streams(): array
    {
        $anthropic = self::sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_vendor', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
        ]);
        $completion = self::sse([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Hel'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]],
        ]);
        $responses = self::sse([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_vendor', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_vendor', 'delta' => 'Hel'],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_vendor', 'delta' => 'lo'],
            ['type' => 'response.completed', 'response' => ['output' => [['type' => 'message', 'content' => [['text' => 'Hello']]]]]],
        ]);
        $cohere = self::sse([
            ['type' => 'message-start', 'id' => 'msg_vendor'],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Hel']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'lo']]]],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE']],
        ]);
        $gemini = self::sse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']]],
        ]);
        $ollama = self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'Hel'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => 'lo'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop'],
        ]);
        return [
            'anthropic' => [static fn (Closure $stop): AIProviderInterface => new Anthropic('key', 'model', httpClient: self::stoppable($anthropic, $stop))],
            'openai' => [static fn (Closure $stop): AIProviderInterface => new OpenAI('key', 'model', httpClient: self::stoppable($completion, $stop))],
            'openai responses' => [static fn (Closure $stop): AIProviderInterface => new OpenAIResponses('key', 'model', httpClient: self::stoppable($responses, $stop))],
            'cohere' => [static fn (Closure $stop): AIProviderInterface => new Cohere('key', 'model', httpClient: self::stoppable($cohere, $stop))],
            'gemini' => [static fn (Closure $stop): AIProviderInterface => new Gemini('key', 'model', httpClient: self::stoppable($gemini, $stop))],
            'mistral' => [static fn (Closure $stop): AIProviderInterface => new Mistral('key', 'model', httpClient: self::stoppable($completion, $stop))],
            'ollama' => [static fn (Closure $stop): AIProviderInterface => new Ollama('http://localhost/api', 'model', httpClient: self::stoppable($ollama, $stop))],
        ];
    }

    public function test_a_stopped_stream_drops_the_reasoning_and_tool_calls_it_left_incomplete(): void
    {
        $body = self::sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_vendor', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'The user wants the weather']],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Checking']],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_vendor', 'name' => 'tool', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"ci']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ty":"Rome"}']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 9]],
        ]);
        $stopped = false;
        $provider = (new Anthropic('key', 'model', httpClient: self::stoppable($body, function () use (&$stopped): bool {
            return $stopped;
        })))->setTools([new ToolStub('tool')]);

        $answer = $provider->stream(new UserMessage('Weather?'));
        foreach ($answer as $chunk) {
            $stopped = $chunk instanceof ToolArgumentChunk; // stopped halfway through the tool call arguments
        }
        $message = $answer->getReturn()->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking', $message->getContent());
        $this->assertNull($message->getReasoning());
    }

    public function test_a_stream_stopped_before_any_text_is_refused(): void
    {
        $provider = new Anthropic('key', 'model', httpClient: self::stoppable(self::sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ]), static fn (): bool => true));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The stream was stopped before the answer started.');

        $this->consume($provider->stream(new UserMessage('Question')));
    }

    /**
     * @param Closure(): bool $shouldStop
     */
    protected static function stoppable(string $body, Closure $shouldStop): StoppableHttpClient
    {
        return new StoppableHttpClient(self::client($body), $shouldStop);
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
