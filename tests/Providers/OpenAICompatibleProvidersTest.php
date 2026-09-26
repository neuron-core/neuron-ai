<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\OpenAILikeResponses;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class OpenAICompatibleProvidersTest extends TestCase
{
    use RecordsHttpRequests;

    protected const CHAT_COMPLETION = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}';
    protected const RESPONSE = '{"status":"completed","output":[{"type":"message","content":[{"type":"output_text","text":"Answer"}]}]}';

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, string, string}>
     */
    public static function providers(): array
    {
        return [
            'grok' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Grok('sk-test', 'model-x', httpClient: $client),
                'https://api.x.ai/v1/chat/completions',
                self::CHAT_COMPLETION,
            ],
            'deepseek' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Deepseek('sk-test', 'model-x', httpClient: $client),
                'https://api.deepseek.com/v1/chat/completions',
                self::CHAT_COMPLETION,
            ],
            'dashscope' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new DashScopeOpenAI('sk-test', 'model-x', httpClient: $client),
                'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                self::CHAT_COMPLETION,
            ],
            'openai like' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAILike('https://llm.internal/v1/', 'sk-test', 'model-x', httpClient: $client),
                'https://llm.internal/v1/chat/completions',
                self::CHAT_COMPLETION,
            ],
            'openai like responses' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAILikeResponses('https://llm.internal/v1', 'sk-test', 'model-x', httpClient: $client),
                'https://llm.internal/v1/responses',
                self::RESPONSE,
            ],
        ];
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $factory
     */
    #[DataProvider('providers')]
    public function test_chat_is_sent_to_the_vendor_endpoint_with_bearer_auth(Closure $factory, string $endpoint, string $response): void
    {
        $provider = $factory($this->recordingClient(new Response(200, body: $response)));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(["POST {$endpoint}"], $this->sentTargets());
        $this->assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        $this->assertSame('model-x', json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)['model']);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('model-x', $provider->getModel());
    }
}
