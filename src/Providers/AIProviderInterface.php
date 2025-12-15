<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\ToolInterface;

interface AIProviderInterface
{
    /**
     * Send predefined instruction to the LLM.
     */
    public function systemPrompt(?string $prompt): AIProviderInterface;

    /**
     * Set the tools to be exposed to the LLM.
     *
     * @param ToolInterface[] $tools
     */
    public function setTools(array $tools): AIProviderInterface;

    /**
     * The component responsible for mapping the NeuronAI Message to the AI provider format.
     */
    public function messageMapper(): MessageMapperInterface;

    /**
     * The component responsible for mapping the NeuronAI Tools to the AI provider format.
     */
    public function toolPayloadMapper(): ToolPayloadMapperInterface;

    /**
     * Send a prompt to the AI agent.
     *
     * @param Message[] $messages
     */
    public function chat(array $messages): Message;

    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks (TextChunk, ReasoningChunk, etc.) during streaming
     * for real-time delivery to the user. The generator MUST return a complete
     * Message object (AssistantMessage or ToolCallMessage) as its final value.
     *
     * @param Message[] $messages
     * @return Generator<int, \NeuronAI\Chat\Messages\Stream\Chunks\TextChunk|\NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk|\NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk|array, mixed, Message>
     */
    public function stream(array|string $messages): Generator;

    /**
     * @param Message[] $messages
     * @param array<string, mixed> $response_schema
     */
    public function structured(array $messages, string $class, array $response_schema): Message;

    /**
     * Set a custom HTTP client implementation.
     *
     * This allows developers to use async-friendly HTTP clients (Amp, ReactPHP)
     * or customize HTTP behavior (retry logic, caching, etc.).
     */
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface;
}
