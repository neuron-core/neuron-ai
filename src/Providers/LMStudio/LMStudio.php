<?php

declare(strict_types=1);

namespace NeuronAI\Providers\LMStudio;

use NeuronAI\Providers\OpenAILike;

class LMStudio extends OpenAILike
{
    public function __construct(
        protected string $model,
        protected string $baseUri = 'http://localhost:1234/v1/',
        protected string $key = 'lm-studio',
        protected array $parameters = [],
    ) {
        parent::__construct($this->baseUri, $this->key, $this->model, $this->parameters);
    }
}
