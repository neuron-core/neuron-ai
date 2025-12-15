<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class OpenAILike extends OpenAI
{
    public function __construct(
        protected string $baseUri,
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);
    }
}
