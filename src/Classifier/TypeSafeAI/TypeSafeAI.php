<?php

declare(strict_types=1);

namespace NeuronAI\Classifier\TypeSafeAI;

use NeuronAI\Classifier\SystemOne\SystemOne;
use NeuronAI\HttpClient\HttpClientInterface;

class TypeSafeAI extends SystemOne
{
    protected string $baseUri = 'https://api.typesafe.ai/v1';

    public function __construct(
        string $key,
        string $model = 'jev-latest',
        ?HttpClientInterface $httpClient = null,
    ) {
        // The declared default rather than a literal, so a subclass that redeclares $baseUri keeps its endpoint.
        parent::__construct($this->baseUri, $model, $key, $httpClient);
    }

    protected function name(): string
    {
        return 'TypeSafeAI';
    }
}
