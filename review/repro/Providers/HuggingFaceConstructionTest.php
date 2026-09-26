<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\HuggingFace;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\HuggingFace\HuggingFace;
use NeuronAI\Providers\HuggingFace\InferenceProvider;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class HuggingFaceConstructionTest extends TestCase
{
    use RecordsHttpRequests;

    protected const RESPONSE = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}';

    public function test_chat_is_routed_through_the_inference_provider(): void
    {
        $provider = new HuggingFace(
            key: 'hf-key',
            model: 'meta-llama/Llama-3.1-8B-Instruct',
            inferenceProvider: InferenceProvider::TOGETHER,
            httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)),
        );

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://router.huggingface.co/together/v1/chat/completions'], $this->sentTargets());
    }

    public function test_hf_inference_routes_to_the_model_path(): void
    {
        $provider = new HuggingFace(
            key: 'hf-key',
            model: 'meta-llama/Llama-3.1-8B-Instruct',
            httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)),
        );

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://router.huggingface.co/hf-inference/models/meta-llama/Llama-3.1-8B-Instruct/v1/chat/completions'], $this->sentTargets());
    }
}
