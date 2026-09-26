<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function implode;
use function iterator_to_array;
use function strcasecmp;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * API keys travel only in their authentication header: never in the URL, the
 * body, or the message of the exception raised for a failed request.
 */
class ProviderCredentialsTest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'sk-SECRET-0123456789abcdef';

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, string, string}>
     */
    public static function providers(): array
    {
        $bearer = 'Bearer '.self::SECRET;

        return [
            'anthropic' => [static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic(self::SECRET, 'model', httpClient: $client), 'x-api-key', self::SECRET],
            'openai' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'openai responses' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAIResponses(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'openai like' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAILike('https://llm.internal/v1', self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'deepseek' => [static fn (HttpClientInterface $client): AIProviderInterface => new Deepseek(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'dashscope' => [static fn (HttpClientInterface $client): AIProviderInterface => new DashScopeOpenAI(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'grok' => [static fn (HttpClientInterface $client): AIProviderInterface => new Grok(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'openai image' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAIImage(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
            'openai text to speech' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAITextToSpeech(self::SECRET, 'model', 'alloy', httpClient: $client), 'Authorization', $bearer],
            'openai speech to text' => [static fn (HttpClientInterface $client): AIProviderInterface => new OpenAISpeechToText(self::SECRET, 'model', httpClient: $client), 'Authorization', $bearer],
        ];
    }

    protected string $audioFile;

    protected function setUp(): void
    {
        $this->audioFile = tempnam(sys_get_temp_dir(), 'neuron_audio_');
        file_put_contents($this->audioFile, 'fake-audio');
    }

    protected function tearDown(): void
    {
        @unlink($this->audioFile);
    }

    protected function prompt(): Message
    {
        return (new UserMessage('Hello'))->addContent(new AudioContent($this->audioFile, SourceType::URL));
    }

    protected function unauthorized(): Response
    {
        return new Response(401, body: '{"error":{"message":"Incorrect API key provided","type":"invalid_request_error"}}');
    }

    protected function assertKeyOnlyInAuthenticationHeader(string $header, string $expected): void
    {
        $request = $this->sentRequests[0]['request'];
        $this->assertSame($expected, $request->getHeaderLine($header));
        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, $header) !== 0) {
                $this->assertStringNotContainsString(self::SECRET, implode(',', $values), "The key leaks through the {$name} header.");
            }
        }
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $factory
     */
    #[DataProvider('providers')]
    public function test_failed_chat_does_not_expose_the_api_key(Closure $factory, string $header, string $expected): void
    {
        $provider = $factory($this->recordingClient($this->unauthorized()));

        try {
            $provider->chat($this->prompt());
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('Incorrect API key provided', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }

        $this->assertKeyOnlyInAuthenticationHeader($header, $expected);
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $factory
     */
    #[DataProvider('providers')]
    public function test_failed_stream_does_not_expose_the_api_key(Closure $factory, string $header, string $expected): void
    {
        $provider = $factory($this->recordingClient($this->unauthorized()));

        try {
            iterator_to_array($provider->stream($this->prompt()));
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }

        $this->assertKeyOnlyInAuthenticationHeader($header, $expected);
    }
}
