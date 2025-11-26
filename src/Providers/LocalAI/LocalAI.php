<?php

declare(strict_types=1);

namespace NeuronAI\Providers\LocalAI;

use NeuronAI\Providers\OpenAILike;

class LocalAI extends OpenAILike
{
    public function __construct(
        protected string $model,
        protected string $baseUri = 'http://localhost:8080/v1/',
        protected string $key = 'local-ai',
        protected array $parameters = [],
    ) {
        parent::__construct($this->baseUri, $this->key, $this->model, $this->parameters);
    }
}
