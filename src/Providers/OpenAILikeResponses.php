<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Providers\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

class OpenAILikeResponses extends OpenAIResponses
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
