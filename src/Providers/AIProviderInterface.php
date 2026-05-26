<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\ToolInterface;

interface AIProviderInterface
{
    public function getModel(): string;

    /**
     * Send predefined instruction to the LLM.
     *
     * @param string|SystemContent[]|null $prompt
     */
    public function systemPrompt(string|array|null $prompt): AIProviderInterface;

    /**
     * Set the tools to be exposed to the LLM.
     *
     * @param ToolInterface[] $tools
     */
    public function setTools(array $tools): AIProviderInterface;

    /**
     * Send a prompt to the AI agent.
     */
    public function chat(Message ...$messages): ProviderResponse;

    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks (TextChunk, ReasoningChunk, etc.) during streaming
     * for real-time delivery to the user. The generator MUST return a
     * ProviderResponse as its final value.
     *
     * @return Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    public function stream(Message ...$messages): Generator;

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     */
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse;

    /**
     * Set a custom HTTP client implementation.
     *
     * This allows developers to use async-friendly HTTP clients (Amp, ReactPHP)
     * or customize HTTP behavior (retry logic, caching, etc.).
     */
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface;
}
