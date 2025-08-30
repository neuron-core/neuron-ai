<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Agent;
use NeuronAI\AgentInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;

/**
 * @method RAG withProvider(AIProviderInterface $provider)
 */
class RAG extends Agent
{
    use ResolveVectorStore;
    use ResolveEmbeddingProvider;
    use ResolveRetrieval;

    /**
     * @var PreProcessorInterface[]
     */
    protected array $preProcessors = [];

    /**
     * @var PostProcessorInterface[]
     */
    protected array $postProcessors = [];

    /**
     * @throws MissingCallbackParameter
     * @throws ToolCallableNotSet
     * @throws \Throwable
     */
    public function chat(Message|array $messages): Message
    {
        $question = \is_array($messages) ? \end($messages) : $messages;

        $this->notify('chat-rag-start');

        $this->withDocumentsContext(
            $this->retrieveDocuments($question)
        );

        $response = parent::chat($messages);

        $this->notify('chat-rag-stop');
        return $response;
    }

    /**
     * @throws \Throwable
     */
    public function stream(Message|array $messages): \Generator
    {
        $question = \is_array($messages) ? \end($messages) : $messages;

        $this->notify('stream-rag-start');

        $this->withDocumentsContext(
            $this->retrieveDocuments($question)
        );

        yield from parent::stream($messages);

        $this->notify('stream-rag-stop');
    }

    public function structured(Message|array $messages, ?string $class = null, int $maxRetries = 1): mixed
    {
        $question = \is_array($messages) ? \end($messages) : $messages;

        $this->notify('structured-rag-start');

        $this->withDocumentsContext(
            $this->retrieveDocuments($question)
        );

        $structured = parent::structured($messages, $class, $maxRetries);

        $this->notify('structured-rag-stop');

        return $structured;
    }

    /**
     * Set the system message based on the context.
     *
     * @param Document[] $documents
     */
    public function withDocumentsContext(array $documents): AgentInterface
    {
        $originalInstructions = $this->resolveInstructions();

        // Remove the old context to avoid infinite grow
        $newInstructions = $this->removeDelimitedContent($originalInstructions, '<EXTRA-CONTEXT>', '</EXTRA-CONTEXT>');

        $newInstructions .= '<EXTRA-CONTEXT>';
        foreach ($documents as $document) {
            $newInstructions .= "Source Type: ".$document->getSourceType().\PHP_EOL.
                "Source Name: ".$document->getSourceName().\PHP_EOL.
                "Content: ".$document->getContent().\PHP_EOL.\PHP_EOL;
        }
        $newInstructions .= '</EXTRA-CONTEXT>';

        $this->withInstructions(\trim($newInstructions));

        return $this;
    }

    /**
     * Retrieve relevant documents using the configured retrieval strategy.
     *
     * @return Document[]
     */
    public function retrieveDocuments(Message $question): array
    {
        $question = $this->applyPreProcessors($question);

        $this->notify('rag-retrieving', new Retrieving($question));

        $documents = $this->resolveRetrieval()->retrieve($question);

        $retrievedDocs = [];

        foreach ($documents as $document) {
            //md5 for removing duplicates
            $retrievedDocs[\md5($document->getContent())] = $document;
        }

        $retrievedDocs = \array_values($retrievedDocs);

        $this->notify('rag-retrieved', new Retrieved($question, $retrievedDocs));

        return $this->applyPostProcessors($question, $retrievedDocs);
    }

    /**
     * Apply a series of preprocessors to the asked question.
     *
     * @return Message The processed question.
     */
    protected function applyPreProcessors(Message $question): Message
    {
        foreach ($this->preProcessors() as $processor) {
            $this->notify('rag-preprocessing', new PreProcessing($processor::class, $question));
            $question = $processor->process($question);
            $this->notify('rag-preprocessed', new PreProcessed($processor::class, $question));
        }

        return $question;
    }

    /**
     * Apply a series of postprocessors to the retrieved documents.
     *
     * @param Document[] $documents The documents to process.
     * @return Document[] The processed documents.
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
     * @param Document[] $documents
     */
    public function addDocuments(array $documents, int $chunkSize = 50): void
    {
        foreach (\array_chunk($documents, $chunkSize) as $chunk) {
            $this->resolveVectorStore()->addDocuments(
                $this->resolveEmbeddingsProvider()->embedDocuments($chunk)
            );
        }
    }

    /**
     * @param Document[] $documents
     */
    public function reindexBySource(array $documents, int $chunkSize = 50): void
    {
        $grouped = [];

        foreach ($documents as $document) {
            $key = $document->sourceType . ':' . $document->sourceName;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $document;
        }

        foreach (\array_keys($grouped) as $key) {
            [$sourceType, $sourceName] = \explode(':', $key);
            $this->resolveVectorStore()->deleteBySource($sourceType, $sourceName);
            $this->addDocuments($grouped[$key], $chunkSize);
        }
    }

    /**
     * @throws AgentException
     */
    public function setPreProcessors(array $preProcessors): RAG
    {
        foreach ($preProcessors as $processor) {
            if (! $processor instanceof PreProcessorInterface) {
                throw new AgentException($processor::class." must implement ".PreProcessorInterface::class);
            }

            $this->preProcessors[] = $processor;
        }

        return $this;
    }

    /**
     * @throws AgentException
     */
    public function setPostProcessors(array $postProcessors): RAG
    {
        foreach ($postProcessors as $processor) {
            if (! $processor instanceof PostProcessorInterface) {
                throw new AgentException($processor::class." must implement ".PostProcessorInterface::class);
            }

            $this->postProcessors[] = $processor;
        }

        return $this;
    }

    /**
     * @return PreProcessorInterface[]
     */
    protected function preProcessors(): array
    {
        return $this->preProcessors;
    }

    /**
     * @return PostProcessorInterface[]
     */
    protected function postProcessors(): array
    {
        return $this->postProcessors;
    }
}
