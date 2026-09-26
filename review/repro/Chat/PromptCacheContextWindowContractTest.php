<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The context window is enforced from the prompt size each provider reports.
 * Two vendors reporting the same prompt, one mostly served from the prompt
 * cache, must lead to the same trimming.
 */
class PromptCacheContextWindowContractTest extends TestCase
{
    use RecordsHttpRequests;

    /**
     * @return array<string, array{Closure(HttpClientInterface): AIProviderInterface, Closure(string, int): array<string, mixed>, Closure(array<string, mixed>): list<string>}>
     */
    public static function providers(): array
    {
        return [
            // Anthropic: input_tokens excludes the tokens read from the cache.
            'anthropic' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new Anthropic('key', 'claude', httpClient: $client),
                static fn (string $text, int $promptTokens): array => ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => $text]],
                    'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => $promptTokens - 10, 'output_tokens' => 10]],
                static fn (array $body): array => array_map(static fn (array $message): string => $message['content'][0]['text'], $body['messages']),
            ],
            // OpenAI: prompt_tokens includes the cached tokens.
            'openai' => [
                static fn (HttpClientInterface $client): AIProviderInterface => new OpenAI('key', 'gpt', httpClient: $client),
                static fn (string $text, int $promptTokens): array => ['id' => 'chatcmpl', 'choices' => [['index' => 0, 'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => $text]]],
                    'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => 10, 'prompt_tokens_details' => ['cached_tokens' => $promptTokens - 10]]],
                static fn (array $body): array => array_map(
                    static fn (array $message): string => $message['content'][0]['text'],
                    array_values(array_filter($body['messages'], static fn (array $message): bool => $message['role'] !== 'system')),
                ),
            ],
        ];
    }

    /**
     * @param Closure(HttpClientInterface): AIProviderInterface $makeProvider
     * @param Closure(string, int): array<string, mixed> $answer
     * @param Closure(array<string, mixed>): list<string> $conversation
     */
    #[DataProvider('providers')]
    public function test_a_prompt_served_from_the_cache_still_counts_toward_the_context_window(
        Closure $makeProvider,
        Closure $answer,
        Closure $conversation,
    ): void {
        $client = $this->recordingClient(
            new Response(200, [], json_encode($answer('First answer', 900), JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode($answer('Second answer', 1800), JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode($answer('Third answer', 900), JSON_THROW_ON_ERROR)),
        );
        $agent = Agent::make(workflowId: 'cached-thread')
            ->setAiProvider($makeProvider($client))
            ->setMessageStore(new InMemoryMessageStore())
            ->setContextWindow(1000);

        $agent->chat(new UserMessage('First question'));
        $agent->chat(new UserMessage('Second question'));
        $agent->chat(new UserMessage('Third question'));

        // After the second exchange the prompt was 1,810 tokens: over the window.
        $this->assertSame(
            ['Second question', 'Second answer', 'Third question'],
            $conversation(json_decode((string) $this->sentRequests[2]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR)),
        );
    }
}
