<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use Aws\BedrockRuntime\BedrockRuntimeClient;

class AwsBedrockEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    public function __construct(
        protected BedrockRuntimeClient $bedrockRuntimeClient,
        protected string $model = 'amazon.titan-embed-text-v2:0',
    ) {
    }

    public function embedText(string $text): array
    {
        $response = $this->bedrockRuntimeClient->invokeModel([
            'modelId' => $this->model,
            'contentType' => 'application/json',
            'body' => \json_encode([
                'inputText' => $text,
            ]),
        ]);

        $response = \json_decode((string) $response['body'], true);

        return $response['embedding'];
    }
}
