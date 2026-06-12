<?php

declare(strict_types=1);

namespace NeuronAI\RAG\ContextInjector;

use NeuronAI\Agent\AgentState;
use NeuronAI\RAG\Document;

/**
 * Strategy for formatting and injecting retrieved documents into the agent context.
 *
 * Implementations may modify the instructions string (e.g. append a context block
 * to the system prompt) and/or mutate the chat history stored in $state (e.g.
 * prepend/append a context block to the last user message).
 *
 * The returned string is used as the instructions for the next inference call.
 */
interface ContextInjectorInterface
{
    /**
     * Inject retrieved documents into the agent context.
     *
     * @param Document[]  $documents    Retrieved documents to embed as context.
     * @param string      $instructions The base system instructions.
     * @param AgentState  $state        Agent state (provides access to chat history).
     * @return string The (potentially enriched) instructions string.
     */
    public function inject(array $documents, string $instructions, AgentState $state): string;
}
