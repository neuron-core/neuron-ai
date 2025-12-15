<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HuggingFace;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;

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
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->buildBaseUri();
        parent::__construct($key, $model, $parameters, $this->strict_response, $httpClient);
    }

    private function buildBaseUri(): void
    {
        $endpoint = match ($this->inferenceProvider) {
            InferenceProvider::HF_INFERENCE => trim($this->inferenceProvider->value, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->model,
            default => trim($this->inferenceProvider->value, DIRECTORY_SEPARATOR),
        };

        $this->baseUri = sprintf($this->baseUri, $endpoint);
    }

}
