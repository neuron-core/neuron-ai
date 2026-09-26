<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\RAG\Document;
use NeuronAI\Tests\Tools\Stub\RecordingRetrieval;
use NeuronAI\Tools\Toolkits\RetrievalTool;
use PHPUnit\Framework\TestCase;

use function json_decode;

class RetrievalToolTest extends TestCase
{
    public function test_exposes_a_single_required_query(): void
    {
        $tool = new RetrievalTool(new RecordingRetrieval());

        $this->assertSame('context_retrieval', $tool->getName());
        $this->assertSame([
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'The query to retrieve documents for.']],
            'required' => ['query'],
        ], $tool->getInputSchema());
    }

    public function test_retrieves_with_the_verbatim_query_as_an_unfiltered_user_message(): void
    {
        $retrieval = new RecordingRetrieval();

        (new RetrievalTool($retrieval))->setInputs(['query' => ' Refund POLICY?'])->execute();

        $this->assertCount(1, $retrieval->queries);
        $this->assertSame(MessageRole::USER->value, $retrieval->queries[0]->getRole());
        $this->assertSame(' Refund POLICY?', $retrieval->queries[0]->getContent());
        $this->assertSame([null], $retrieval->filters);
    }

    public function test_returns_the_documents_as_json(): void
    {
        $document = (new Document('Refunds are accepted within 30 days.'))->setSourceName('faq.md')->setScore(0.9);
        $tool = (new RetrievalTool(new RecordingRetrieval([$document])))->setInputs(['query' => 'refund']);

        $tool->execute();

        $result = json_decode((string) $tool->getResult(), true);
        $this->assertCount(1, $result);
        $this->assertSame('Refunds are accepted within 30 days.', $result[0]['content']);
        $this->assertSame('faq.md', $result[0]['sourceName']);
        $this->assertSame(0.9, $result[0]['score']);
    }

    public function test_no_documents_is_an_empty_list(): void
    {
        $tool = (new RetrievalTool(new RecordingRetrieval()))->setInputs(['query' => 'unknown']);

        $tool->execute();

        $this->assertSame('[]', $tool->getResult());
    }
}
