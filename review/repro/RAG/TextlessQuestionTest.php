<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\TestCase;

class TextlessQuestionTest extends TestCase
{
    public function test_an_image_only_question_skips_similarity_retrieval_instead_of_crashing(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $vectorStore = new FakeVectorStore([new Document('Context')]);
        $rag = RAG::make()
            ->setEmbeddingsProvider($embeddings)
            ->setVectorStore($vectorStore);
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('A cat.')));

        $answer = $rag->chat(new UserMessage(new ImageContent('https://example.com/cat.png', SourceType::URL)))->getMessage();

        $this->assertSame('A cat.', $answer->getContent());
        $embeddings->assertNothingEmbedded();
        $vectorStore->assertSearchCount(0);
    }
}
