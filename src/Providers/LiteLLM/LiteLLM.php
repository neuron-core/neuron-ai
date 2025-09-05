<?php

declare(strict_types=1);

namespace NeuronAI\Providers\LiteLLM;

use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\OpenAI;

class LiteLLM extends OpenAI
{
    public function __construct(
        protected string $baseUri,
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpOptions);
    }
}
