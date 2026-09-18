<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

use function array_reverse;

class ConversationIngestionNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    public function __construct(
        protected readonly VectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
        ChatHistoryInterface $chatHistory,
    ) {
        $this->chatHistory = $chatHistory;
    }

    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $assistant = $state->getMessage();
        $user = $this->lastUserMessage($state->request->messages)
            ?? $this->lastUserMessage($this->chatHistory->getMessages());

        if (
            !$assistant instanceof AssistantMessage || $assistant instanceof ToolCallMessage
            || $user?->getContent() === null || $assistant->getContent() === null
        ) {
            return new StopEvent();
        }

        $threadId = $this->chatHistory->getThreadId() ?? throw new ChatHistoryException(
            'Conversation ingestion requires a thread identity.'
        );

        $this->memoize('conversation.ingest', function () use ($threadId, $user, $assistant): bool {
            $document = (new Document("User: {$user->getContent()}\nAssistant: {$assistant->getContent()}"))
                ->setSourceType(SemanticMemoryRetrieval::SOURCE_TYPE)
                ->setSourceName($threadId);

            $this->vectorStore->getSchema()->validate($document);
            $this->vectorStore->addDocument($this->embeddingProvider->embedDocument($document));

            return true;
        });

        return new StopEvent();
    }

    /** @param Message[] $messages */
    protected function lastUserMessage(array $messages): ?UserMessage
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                return $message;
            }
        }

        return null;
    }
}
