<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Client;
use Google\Auth\Credentials\ServiceAccountCredentials;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_filter;
use function array_map;

class GeminiVertex implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri;

    /**
     * System instructions.
     */
    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolPayloadMapperInterface $toolPayloadMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $pathJsonCredentials,
        protected string $model,
        protected string $location,
        protected string $projectId,
        protected array $parameters = [],
        protected ?HttpClientOptions $httpOptions = null,
    ) {

        $creds = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $pathJsonCredentials
        );

        $token = $creds->fetchAuthToken();
        $key = $token['access_token'];

        $this->baseUri = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$projectId}/locations/{$location}/publishers/google/models";
        $config = [
            // Since Gemini use colon ":" into the URL, guzzle fires an exception using base_uri configuration.
            //'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key
            ]
        ];

        if ($this->httpOptions instanceof HttpClientOptions) {
            $config = $this->mergeHttpOptions($config, $this->httpOptions);
        }

        $this->client = new Client($config);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapper();
    }

    /**
     * @param array<string, mixed> $message
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $message): Message
    {
        $signature = null;

        $tools = array_map(function (array $item) use (&$signature): ?ToolInterface {
            if (!isset($item['functionCall'])) {
                return null;
            }

            if ($item['thoughtSignature'] ?? false) {
                $signature = $item['thoughtSignature'];
            }

            // Gemini does not use ID. It uses the tool's name as a unique identifier.
            return $this->findTool($item['functionCall']['name'])
                ->setInputs($item['functionCall']['args'])
                ->setCallId($item['functionCall']['name']);
        }, $message['parts']);

        $result = new ToolCallMessage(
            $message['content'] ?? null,
            array_filter($tools)
        );

        if ($signature !== null) {
            $result->addMetadata('thoughtSignature', $signature);
        }

        return $result;
    }
}
