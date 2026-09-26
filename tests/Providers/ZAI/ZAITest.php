<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ZAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Providers\ZAI\MessageMapper;
use NeuronAI\Providers\ZAI\ToolMapper;
use NeuronAI\Providers\ZAI\ZAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

class ZAITest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'zai-SECRET-0123456789';

    /**
     * @param array<string, mixed> $message
     */
    protected static function completion(array $message): string
    {
        return json_encode([
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', ...$message]]],
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
        ], JSON_THROW_ON_ERROR);
    }

    protected function provider(string ...$responses): ZAI
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = new Response(200, body: $response);
        }

        return new ZAI(self::SECRET, 'glm-4.6', httpClient: $this->recordingClient(...$queue));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chat_targets_the_zai_endpoint_with_bearer_authentication(): void
    {
        $this->provider(self::completion(['content' => 'Hi']))->chat(new UserMessage('Hello'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.z.ai/api/paas/v4/chat/completions'], $this->sentTargets());
        $this->assertSame('Bearer '.self::SECRET, $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
        $this->assertSame('glm-4.6', $this->sentBody()['model']);
    }

    public function test_custom_base_uri_is_honoured(): void
    {
        $provider = new ZAI('key', 'glm-4.6', httpClient: $this->recordingClient(new Response(200, body: self::completion(['content' => 'Hi']))), baseUri: 'https://open.bigmodel.cn/api/paas/v4/');

        $provider->chat(new UserMessage('Hello'));

        $this->assertSame(['POST https://open.bigmodel.cn/api/paas/v4/chat/completions'], $this->sentTargets());
    }

    public function test_reasoning_content_is_kept_next_to_the_answer(): void
    {
        $message = $this->provider(self::completion(['content' => 'Answer', 'reasoning_content' => 'Thinking']))
            ->chat(new UserMessage('Q'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('Thinking', $message->getReasoning()?->content);
        $this->assertSame([8, 3], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        $provider = new ZAI(self::SECRET, 'glm-4.6', httpClient: $this->recordingClient(new Response(401, body: '{"error":{"code":"1000","message":"Authentication failed"}}')));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertStringContainsString('Authentication failed', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_reasoning_is_sent_back_as_reasoning_content_not_as_a_content_block(): void
    {
        $mapped = (new MessageMapper())->map([new AssistantMessage([new ReasoningContent('Thinking'), new TextContent('Answer')])]);

        $this->assertSame([[
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Answer']],
            'reasoning_content' => 'Thinking',
        ]], $mapped);
    }

    /**
     * @return array<string, array{ContentBlockInterface, array<string, mixed>|null}>
     */
    public static function content_blocks(): array
    {
        return [
            'image url' => [new ImageContent('https://x.test/a.png', SourceType::URL, 'image/png'), ['type' => 'image_url', 'image_url' => ['url' => 'https://x.test/a.png']]],
            'image base64' => [new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png'), ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0=']]],
            'file url' => [new FileContent('https://x.test/a.pdf', SourceType::URL, 'application/pdf'), ['type' => 'file_url', 'file_url' => ['url' => 'https://x.test/a.pdf']]],
            'file base64 is unsupported' => [new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf'), null],
            'file id is unsupported' => [new FileContent('file-1', SourceType::ID, 'application/pdf'), null],
            'video url' => [new VideoContent('https://x.test/v.mp4', SourceType::URL, 'video/mp4'), ['type' => 'video_url', 'video_url' => ['url' => 'https://x.test/v.mp4']]],
            'video base64 is unsupported' => [new VideoContent('AAAA', SourceType::BASE64, 'video/mp4'), null],
            'audio is unsupported' => [new AudioContent('SUQz', SourceType::BASE64, 'audio/mpeg'), null],
        ];
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('content_blocks')]
    public function test_content_blocks_are_mapped_to_zai_parts(ContentBlockInterface $block, ?array $expected): void
    {
        $content = (new MessageMapper())->map([new UserMessage([new TextContent('See'), $block])])[0]['content'];

        $text = ['type' => 'text', 'text' => 'See'];
        $this->assertSame($expected === null ? [$text] : [$text, $expected], $content);
    }

    public function test_function_and_provider_tools_are_mapped_side_by_side(): void
    {
        $mapped = (new ToolMapper())->map([
            new ToolStub('lookup', 'Look it up'),
            new ProviderTool('web_search', options: ['enable' => true, 'search_engine' => 'search_pro']),
        ]);

        $this->assertSame('function', $mapped[0]['type']);
        $this->assertSame('lookup', $mapped[0]['function']['name']);
        $this->assertSame(['type' => 'web_search', 'web_search' => ['enable' => true, 'search_engine' => 'search_pro']], $mapped[1]);
    }

    public function test_structured_output_requests_json_and_describes_the_schema_only_for_that_call(): void
    {
        $provider = $this->provider(self::completion(['content' => '{"name":"Ada"}']), self::completion(['content' => 'plain']));
        $provider->systemPrompt('Base');
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $provider->structured(new UserMessage('Who?'), 'Person', $schema);
        $provider->chat(new UserMessage('Plain'));

        $structured = $this->sentBody(0);
        $this->assertSame(['type' => 'json_object'], $structured['response_format']);
        $this->assertSame(
            [['type' => 'text', 'text' => "Base\n\n---\n\nGenerate a JSON with the following schema: \n\n".json_encode($schema, JSON_PRETTY_PRINT)]],
            $structured['messages'][0]['content'],
        );
        $plain = $this->sentBody(1);
        $this->assertArrayNotHasKey('response_format', $plain);
        $this->assertSame([['type' => 'text', 'text' => 'Base']], $plain['messages'][0]['content']);
    }

    public function test_structured_output_restores_the_configuration_when_the_request_fails(): void
    {
        $provider = new ZAI('key', 'glm-4.6', ['temperature' => 0.1], httpClient: $this->recordingClient(
            new Response(500, body: '{"error":{"message":"boom"}}'),
            new Response(200, body: self::completion(['content' => 'plain'])),
        ));

        try {
            $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object']);
            $this->fail('A 500 response must raise an HttpException.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Plain'));

        $plain = $this->sentBody(1);
        $this->assertArrayNotHasKey('response_format', $plain);
        $this->assertSame(0.1, $plain['temperature']);
        $this->assertSame('user', $plain['messages'][0]['role']);
    }
}
