<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HuggingFace;

use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\OpenAI;

class HuggingFace extends OpenAI
{
    protected string $baseUri = 'https://router.huggingface.co/%s/v1';

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected ?InferenceProvider $inferenceProvider = InferenceProvider::HF_INFERENCE,
        protected bool $strict_response = false,
        protected array $parameters = [],
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        $this->buildBaseUri();
        parent::__construct($key, $model, $parameters, $this->strict_response, $httpOptions);
    }

    private function buildBaseUri(): void
    {
        $endpoint = match ($this->inferenceProvider) {
            InferenceProvider::HF_INFERENCE => \trim($this->inferenceProvider->value, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.$this->model,
            default => \trim($this->inferenceProvider->value, \DIRECTORY_SEPARATOR),
        };

        $this->baseUri = \sprintf($this->baseUri, $endpoint);
    }

}
