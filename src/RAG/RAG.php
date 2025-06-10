<?php

namespace NeuronAI\RAG;

use NeuronAI\Agent;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\InstructionsChanged;
use NeuronAI\Observability\Events\InstructionsChanging;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\VectorStoreResult;
use NeuronAI\Observability\Events\VectorStoreSearching;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * @method RAG withProvider(AIProviderInterface $provider)
 */
class RAG extends Agent
{
    use ResolveVectorStore;
    use ResolveEmbeddingProvider;

    /**
     * @var array<PostprocessorInterface>
     */
    protected array $postProcessors = [];

    /**
     * @throws MissingCallbackParameter
     * @throws ToolCallableNotSet
     * @throws \Throwable
     */
    public function answer(Message $question): Message
    {
        $this->notify('rag-start');

        $this->retrieval($question);

        $response = $this->chat($question);

        $this->notify('rag-stop');
        return $response;
    }

    public function streamAnswer(Message $question): \Generator
    {
        $this->notify('rag-start');

        $this->retrieval($question);

        yield from $this->stream($question);

        $this->notify('rag-stop');
    }

    protected function retrieval(Message $question): void
    {
        $this->withDocumentsContext(
            $this->retrieveDocuments($question)
        );
    }

    /**
     * Set the system message based on the context.
     *
     * @param array<Document> $documents
     * @return AgentInterface
     */
    public function withDocumentsContext(array $documents): AgentInterface
    {
        $originalInstructions = $this->instructions();
        $this->notify('rag-instructions-changing', new InstructionsChanging($originalInstructions));

        // Remove the old context to avoid infinite grow
        $newInstructions = $this->removeDelimitedContent($originalInstructions, '<EXTRA-CONTEXT>', '</EXTRA-CONTEXT>');

        $newInstructions .= '<EXTRA-CONTEXT>';
        foreach ($documents as $document) {
            $newInstructions .= $document->content.PHP_EOL.PHP_EOL;
        }
        $newInstructions .= '</EXTRA-CONTEXT>';

        $this->withInstructions(\trim($newInstructions));
        $this->notify('rag-instructions-changed', new InstructionsChanged($originalInstructions, $this->instructions()));

        return $this;
    }

    /**
     * @deprecated Use withDocumentsContext instead.
     */
    protected function setSystemMessage(array $documents): AgentInterface
    {
        return $this->withDocumentsContext($documents);
    }

    /**
     * Retrieve relevant documents from the vector store.
     *
     * @return array<Document>
     */
    public function retrieveDocuments(Message $question): array
    {
        $this->notify('rag-vectorstore-searching', new VectorStoreSearching($question));

        $docs = $this->resolveVectorStore()->similaritySearch(
            $this->resolveEmbeddingsProvider()->embedText($question->getContent())
        );

        $retrievedDocs = [];

        foreach ($docs as $doc) {
            //md5 for removing duplicates
            $retrievedDocs[\md5($doc->content)] = $doc;
        }

        $retrievedDocs = \array_values($retrievedDocs);

        $this->notify('rag-vectorstore-result', new VectorStoreResult($question, $retrievedDocs));

        return $this->applyPostProcessors($question, $retrievedDocs);
    }

    /**
     * Apply a series of postprocessors to the retrieved documents.
     *
     * @param Message $question The question to process the documents for.
     * @param array<Document> $documents The documents to process.
     * @return array<Document> The processed documents.
     */
    protected function applyPostProcessors(Message $question, array $documents): array
    {
        foreach ($this->postProcessors() as $processor) {
            $this->notify('rag-postprocessing', new PostProcessing($processor::class, $question, $documents));
            $documents = $processor->process($question, $documents);
            $this->notify('rag-postprocessed', new PostProcessed($processor::class, $question, $documents));
        }

        return $documents;
    }

    /**
     * Feed the vector store with documents.
     *
     * @param array<Document> $documents
     * @return void
     */
    public function addDocuments(array $documents): void
    {
        $this->resolveVectorStore()->addDocuments(
            $this->resolveEmbeddingsProvider()->embedDocuments($documents)
        );
    }

    /**
     * @throws AgentException
     */
    public function setPostProcessors(array $postProcessors): RAG
    {
        foreach ($postProcessors as $processor) {
            if (! $processor instanceof PostProcessorInterface) {
                throw new AgentException($processor::class." must implement PostProcessorInterface");
            }

            $this->postProcessors[] = $processor;
        }

        return $this;
    }

    /**
     * @return PostProcessorInterface[]
     */
    protected function postProcessors(): array
    {
        return $this->postProcessors;
    }
}
