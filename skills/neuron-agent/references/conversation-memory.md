# Conversation memory

Conversation excerpts are RAG documents. Recall them with `SemanticMemoryRetrieval`, and opt into creating them with `ConversationIngestionNode`. The Agent inference/tool loop needs no memory configuration.

## Recall conversations alongside documents

`SemanticMemoryRetrieval` takes a vector store, an embeddings provider, and the exact thread IDs to search. It builds the conversation source and thread filters internally:

```php
use NeuronAI\RAG\Retrieval\CompositeRetrieval;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;

$rag->setRetrieval(new CompositeRetrieval([
    new SemanticMemoryRetrieval(
        vectorStore: $conversationStore,
        embeddingProvider: $embeddings,
        threadIds: $authorizedThreadIds,
    ),
    new SimilarityRetrieval(
        vectorStore: $knowledgeStore,
        embeddingProvider: $embeddings,
    ),
]));
```

For recall alone, pass `SemanticMemoryRetrieval` directly to `setRetrieval()`. The thread list must be non-empty and contain non-empty strings. Supply IDs authorized by the application; the current thread is not added implicitly. Configure `topK` on the conversation store to limit results across all allowed threads.

`CompositeRetrieval` calls its children in order and combines their results. Each child receives the same preprocessed query and mandatory per-run filters. Put collection-specific filters on that child rather than in the shared retrieval scope. Shared filters must be supported by every child and are never dropped. RAG then deduplicates by content and runs the common postprocessors. A global reranker or limit applies to the combined set; the composite neither compares scores across stores nor reserves a quota for each source.

Retrieved excerpts enter the existing `<EXTRA-CONTEXT>` block, with their source type and thread as source name. They do not enter chat history. Describe past excerpts as contextual data in your agent instructions when defining how the model should use them.

## Opt into creating conversation documents

Override `exitNodes()` on an Agent or RAG subclass. Return the ingestion node in place of the default `AgentEndNode`:

```php
use NeuronAI\RAG\Nodes\ConversationIngestionNode;
use NeuronAI\RAG\RAG;

class RememberingAssistant extends RAG
{
    // Define the usual provider(), vectorStore(), and embeddings() hooks.
    // This example uses the configured RAG store for conversation documents.

    /** @param \NeuronAI\Agent\AgentExecution $execution */
    protected function exitNodes(\NeuronAI\Workflow\WorkflowExecution $execution): array
    {
        return [new ConversationIngestionNode(
            vectorStore: $this->resolveVectorStore(),
            embeddingProvider: $this->resolveEmbeddingsProvider(),
            chatHistory: $execution->getChatHistory(),
        )];
    }
}
```

To store conversations separately from your knowledge base, inject the conversation store and matching embeddings provider instead. Use a durable store for cross-process retrieval. The default document schema suffices; the node writes `sourceType = 'conversation'` and `sourceName = current thread ID`. A schema requiring additional metadata needs a customized ingestion node that supplies it.

The node handles `AgentOutputEvent` and returns `StopEvent`. Do not include `parent::exitNodes()` alongside it: both would handle the same event. Workflows with other output stages must explicitly compose their routing; registration order does not establish a chain.

Each completed turn stores one document:

```text
User: <user text>
Assistant: <final assistant text>
```

Chat, streaming and structured output share this output boundary. Tool calls and tool results are excluded. A tool-approval pause, failed inference, or stream that has not finished does not reach ingestion. Exchanges without user or assistant text are skipped. Chat history remains a separate service, and the node uses its current thread identity.

An output failure can happen after the response was generated and written to history. Reconstruct the same workflow persistence, history and dependencies, then call `run()` or `events()` to recover. Committed inference is reused, and an ingestion memo prevents repeating a committed write. As with other external side effects, a process failure after the store accepts the write but before its memo commits can repeat that write.

Creation and recall are independent: attach the output node without a memory retriever to store only, or configure the retriever without the output node to recall only. Keep the same graph configuration when resuming a suspended or failed turn.

## Delete stored conversations explicitly

History reset and vector storage have separate lifecycles. `resetConversation()` clears working history and abandons a pending execution; it does not delete RAG documents. Delete only the selected conversation's documents with the standard filter API:

```php
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

$conversationStore->delete(FilterGroup::and(
    Filter::eq('sourceType', SemanticMemoryRetrieval::SOURCE_TYPE),
    Filter::eq('sourceName', $threadId),
));
```

Observe retrieval through `Retrieving` / `Retrieved` and ingestion through `WorkflowNodeStart` / `WorkflowNodeEnd` for `ConversationIngestionNode`. Failures use `AgentError`.
