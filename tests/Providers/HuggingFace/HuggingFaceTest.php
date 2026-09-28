<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\HuggingFace;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\HuggingFace\HuggingFace;
use NeuronAI\Providers\HuggingFace\InferenceProvider;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class HuggingFaceTest extends TestCase
{
    use RecordsHttpRequests;

    protected const RESPONSE = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}';

    public function test_the_router_picks_the_provider_when_none_is_named(): void
    {
        $provider = new HuggingFace('hf-key', 'meta-llama/Llama-3.1-8B-Instruct', httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://router.huggingface.co/v1/chat/completions'], $this->sentTargets());
        $this->assertSame('Bearer hf-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('meta-llama/Llama-3.1-8B-Instruct', json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)['model']);
        $this->assertSame('Answer', $message->getContent());
    }

    #[TestWith([InferenceProvider::TOGETHER, 'meta-llama/Llama-3.1-8B-Instruct:together'])]
    #[TestWith([InferenceProvider::HF_INFERENCE, 'meta-llama/Llama-3.1-8B-Instruct:hf-inference'])]
    public function test_a_named_inference_provider_is_appended_to_the_model(InferenceProvider $inferenceProvider, string $model): void
    {
        $provider = new HuggingFace('hf-key', 'meta-llama/Llama-3.1-8B-Instruct', $inferenceProvider, httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)));

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://router.huggingface.co/v1/chat/completions'], $this->sentTargets());
        $this->assertSame($model, json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR)['model']);
    }
}
