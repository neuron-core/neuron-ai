<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Jina;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key, ?HttpClientInterface $httpClient = null)
 */
class JinaToolkit extends AbstractToolkit
{
    public function __construct(
        protected string $key,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @return array<Tool>
     */
    public function provide(): array
    {
        return [
            new JinaWebSearch($this->key, httpClient: $this->httpClient),
            new JinaUrlReader($this->key, $this->httpClient),
        ];
    }
}
