<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\DeedPage;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(?string $token = null, ?HttpClientInterface $httpClient = null)
 */
class DeedPageToolkit extends AbstractToolkit
{
    /**
     * @param string|null $token deed.page owner token. Without one, the first publish creates an owner
     *                           and the tool keeps its token for the rest of the run.
     */
    public function __construct(
        protected ?string $token = null,
        protected ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function guidelines(): ?string
    {
        return <<<GUIDELINES
            Use publish_web_page only when the user asks for a page they can open or share by link.
            Everything you publish is public: never include secrets or private data. Answer with the url it returns.
            GUIDELINES;
    }

    public function provide(): array
    {
        return [
            new DeedPagePublishTool($this->token, $this->httpClient),
        ];
    }
}
