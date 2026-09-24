<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function array_values;
use function uniqid;

class Ollama implements AIProviderInterface
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected string $baseUri;

    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolMapperInterface $toolPayloadMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $url, // http://localhost:11434/api
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        // Use provided client or create default Guzzle client
        // Provider always configures base URI
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->baseUri = $url;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, array|string|null $content = null): ToolCallMessage
    {
        $tools = [];
        foreach (array_values($toolCalls) as $index => $item) {
            // Ollama sends no call id, but the framework treats callId as per-call identity
            // (memoization, approval decisions, stream protocols): synthesize a locally-unique
            // one. The mapper never sends it back, since Ollama matches results by tool name.
            $tools[] = $this->newToolCall(
                $item['function']['name'],
                uniqid($item['function']['name'].'_'.$index.'_'),
                $item['function']['arguments'],
            );
        }

        return new ToolCallMessage($content, $tools);
    }
}
