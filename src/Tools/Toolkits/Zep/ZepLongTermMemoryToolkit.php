<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $key, string $user_id, ?HttpClientInterface $httpClient = null)
 * @deprecated The Zep toolkit will be removed in the next major version.
 */
class ZepLongTermMemoryToolkit extends AbstractToolkit
{
    public function __construct(
        protected string $key,
        protected string $user_id,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function provide(): array
    {
        return [
            ZepSearchGraphTool::make($this->key, $this->user_id, $this->httpClient),
            ZepAddToGraphTool::make($this->key, $this->user_id, $this->httpClient),
        ];
    }
}
