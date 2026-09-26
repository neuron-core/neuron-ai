<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\Tests\RAG\Stub\StaticRetrieval;
use PHPUnit\Framework\TestCase;

class DeduplicationKeepsFirstTest extends TestCase
{
    public function test_the_best_ranked_duplicate_is_kept(): void
    {
        $best = (new Document('Paris'))->setSourceName('atlas.md')->setScore(0.9);
        $worse = (new Document('Paris'))->setSourceName('notes.md')->setScore(0.2);
        $other = (new Document('Rome'))->setScore(0.5);

        $result = (new RetrievalNode(new StaticRetrieval([$best, $other, $worse])))(
            new QueryPreProcessedEvent(new UserMessage('Question')),
            new AgentState(),
        );

        $this->assertSame([$best, $other], $result->documents);
    }
}
