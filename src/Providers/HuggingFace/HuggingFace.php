<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HuggingFace;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class HuggingFace extends OpenAI
{
    protected string $baseUri = 'https://router.huggingface.co/v1';

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected ?InferenceProvider $inferenceProvider = null,
        protected bool $strict_response = false,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        // The router serves the provider named after the colon, or picks the fastest one without it
        $model = $inferenceProvider instanceof InferenceProvider ? "{$model}:{$inferenceProvider->value}" : $model;

        parent::__construct($key, $model, $parameters, $this->strict_response, $httpClient);
    }
}
