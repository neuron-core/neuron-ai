<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\ResponseInterface;

use function array_key_exists;
use function in_array;
use function json_decode;
use function json_encode;
use function trim;

trait HandleChat
{
    /**
     * Finish reasons that indicate a blocked response (potentially retryable).
     *
     * @var array<string>
     */
    private static array $blockedFinishReasons = [
        'SAFETY',
        'BLOCKLIST',
        'OTHER',
        'RECITATION',
    ];

    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $json = [
            'contents' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        if (isset($this->system)) {
            $json['system_instruction'] = [
                'parts' => [
                    ['text' => $this->system]
                ]
            ];
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync(trim($this->baseUri, '/')."/{$this->model}:generateContent", [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $response): Message {
                $result = json_decode($response->getBody()->getContents(), true);

                // Handle missing or empty candidates
                if (!isset($result['candidates']) || empty($result['candidates'])) {
                    throw new ProviderException(
                        'Gemini API returned no candidates. Response: ' . json_encode($result)
                    );
                }

                $candidate = $result['candidates'][0];
                $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';
                $content = $candidate['content'] ?? [];

                // Handle missing 'parts' for all finish reasons
                if (!isset($content['parts']) || empty($content['parts'])) {
                    // Blocked responses (SAFETY, BLOCKLIST, OTHER, RECITATION) - throw retryable exception
                    if (in_array($finishReason, self::$blockedFinishReasons, true)) {
                        throw new ProviderException(
                            "Gemini response blocked (finishReason: {$finishReason}). " .
                            'This may be transient - retry recommended.'
                        );
                    }

                    // MAX_TOKENS or other - return empty message
                    $role = $content['role'] ?? 'model';
                    return new Message(MessageRole::from($role), '');
                }

                $parts = $content['parts'];

                if (array_key_exists('functionCall', $parts[0]) && !empty($parts[0]['functionCall'])) {
                    $response = $this->createToolCallMessage($content);
                } else {
                    $response = new AssistantMessage($parts[0]['text'] ?? '');
                }

                if (array_key_exists('groundingMetadata', $result['candidates'][0])) {
                    $response->addMetadata('groundingMetadata', $result['candidates'][0]['groundingMetadata']);
                }

                // Attach the usage for the current interaction
                if (array_key_exists('usageMetadata', $result)) {
                    $response->setUsage(
                        new Usage(
                            $result['usageMetadata']['promptTokenCount'],
                            $result['usageMetadata']['candidatesTokenCount'] ?? 0
                        )
                    );
                }

                return $response;
            });
    }
}
