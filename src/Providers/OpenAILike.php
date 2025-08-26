<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Providers\OpenAI\OpenAI;

class OpenAILike extends OpenAI
{
    public function __construct(
        protected string $baseUri,
        string $key,
        string $model,
        array $parameters = [],
        bool $strict_response = false,
        ?HttpClientOptions $httpOptions = null
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpOptions);
    }
}
