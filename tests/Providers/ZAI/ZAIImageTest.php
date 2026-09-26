<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ZAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ZAI\Image\ZAIImage;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class ZAIImageTest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'zai-SECRET-0123456789';
    protected const GENERATED = '{"created":1,"data":[{"url":"https://cdn.z.ai/img/abc.png"}],"usage":{"prompt_tokens":9,"completion_tokens":0}}';

    protected function provider(Response $response): ZAIImage
    {
        return new ZAIImage(self::SECRET, 'cogview-4', ['size' => '1024x1024', 'quality' => 'hd'], $this->recordingClient($response));
    }

    public function test_prompt_and_parameters_are_posted_to_the_generations_endpoint(): void
    {
        $this->provider(new Response(200, body: self::GENERATED))->chat(new UserMessage('ignored'), new UserMessage('A red fox'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.z.ai/api/paas/v4/images/generations'], $this->sentTargets());
        $this->assertSame('Bearer '.self::SECRET, $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['model' => 'cogview-4', 'prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'hd'],
            json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_system_prompt_is_appended_to_the_generation_prompt(): void
    {
        $provider = $this->provider(new Response(200, body: self::GENERATED));
        $provider->systemPrompt('Photorealistic style');

        $provider->chat(new UserMessage('A red fox'));

        $this->assertSame('A red fox Photorealistic style', json_decode((string) $this->sentRequests[0]['request']->getBody(), true)['prompt']);
    }

    public function test_generated_image_url_and_usage_are_returned(): void
    {
        $message = $this->provider(new Response(200, body: self::GENERATED))->chat(new UserMessage('A red fox'))->message();

        $image = $message->getImage();
        $this->assertSame('https://cdn.z.ai/img/abc.png', $image->content);
        $this->assertSame(SourceType::URL, $image->sourceType);
        $this->assertSame([9, 0], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_answer_without_usage_has_no_usage(): void
    {
        $message = $this->provider(new Response(200, body: '{"data":[{"url":"https://cdn.z.ai/x.png"}]}'))->chat(new UserMessage('Fox'))->message();

        $this->assertNull($message->getUsage());
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        try {
            $this->provider(new Response(400, body: '{"error":{"message":"prompt rejected"}}'))->chat(new UserMessage('Fox'));
            $this->fail('A 400 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertStringContainsString('prompt rejected', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_streaming_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Streaming not supported for image generation. Use chat() instead.');

        iterator_to_array($this->provider(new Response(200))->stream(new UserMessage('Fox')));
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output not supported for image generation. Use chat() instead.');

        $this->provider(new Response(200))->structured(new UserMessage('Fox'), 'Image', []);
    }
}
