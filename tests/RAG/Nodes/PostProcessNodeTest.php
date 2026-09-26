<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\Tests\RAG\Stub\LimitPostProcessor;
use PHPUnit\Framework\TestCase;

class PostProcessNodeTest extends TestCase
{
    public function test_without_post_processors_the_retrieved_documents_pass_through(): void
    {
        $query = new UserMessage('Question');
        $documents = [new Document('First'), new Document('Second')];

        $result = (new PostProcessNode([]))(new DocumentsRetrievedEvent($query, $documents), new AgentState());

        $this->assertSame($query, $result->query);
        $this->assertSame($documents, $result->documents);
    }

    public function test_post_processors_run_in_order_each_on_the_previous_output_with_the_same_query(): void
    {
        $query = new UserMessage('Question');
        $documents = [new Document('First'), new Document('Second'), new Document('Third')];
        $first = new LimitPostProcessor(2);
        $second = new LimitPostProcessor(1);

        $result = (new PostProcessNode([$first, $second]))(new DocumentsRetrievedEvent($query, $documents), new AgentState());

        $this->assertSame([['question' => $query, 'documents' => $documents]], $first->received);
        $this->assertSame([['question' => $query, 'documents' => [$documents[0], $documents[1]]]], $second->received);
        $this->assertSame([$documents[0]], $result->documents);
        $this->assertSame($query, $result->query);
    }
}
