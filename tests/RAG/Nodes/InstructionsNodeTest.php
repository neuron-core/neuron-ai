<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use PHPUnit\Framework\TestCase;

class InstructionsNodeTest extends TestCase
{
    protected function enter(SystemMessage $instructions): AgentState
    {
        $state = new AgentState();
        (new PreProcessNode([]))(
            new AgentStartEvent([new UserMessage('Question')]),
            $state,
            AgentResourcesFactory::make(instructions: $instructions),
        );

        return $state;
    }

    public function test_documents_are_appended_as_one_trailing_block_in_retrieval_order(): void
    {
        $state = $this->enter(new SystemMessage('Base instructions'));
        $documents = [
            (new Document('Paris is the capital of France.'))->setSourceType('file')->setSourceName('europe.md'),
            new Document('Rome is the capital of Italy.'),
        ];

        (new InstructionsNode())(new DocumentsProcessedEvent(new UserMessage('Question'), $documents), $state);

        $blocks = $state->request->instructions->getContentBlocks();
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(SystemContent::class, $blocks[1]);
        $this->assertSame(
            "<EXTRA-CONTEXT>"
            ."Source Type: file\nSource Name: europe.md\nContent: Paris is the capital of France.\n\n"
            ."Source Type: manual\nSource Name: manual\nContent: Rome is the capital of Italy.\n\n"
            ."</EXTRA-CONTEXT>",
            $blocks[1]->content,
        );
    }

    public function test_base_instruction_blocks_keep_their_content_and_cache_flag(): void
    {
        $state = $this->enter(new SystemMessage([(new SystemContent('Cached base'))->cache(), new SystemContent('Plain base')]));

        (new InstructionsNode())(new DocumentsProcessedEvent(new UserMessage('Question'), [new Document('Context')]), $state);

        $blocks = $state->request->instructions->getContentBlocks();
        $this->assertCount(3, $blocks);
        $this->assertInstanceOf(SystemContent::class, $blocks[0]);
        $this->assertInstanceOf(SystemContent::class, $blocks[1]);
        $this->assertInstanceOf(SystemContent::class, $blocks[2]);
        $this->assertSame(['Cached base', true], [$blocks[0]->content, $blocks[0]->isCached()]);
        $this->assertSame(['Plain base', false], [$blocks[1]->content, $blocks[1]->isCached()]);
        $this->assertFalse($blocks[2]->isCached(), 'Per-question context must not be marked for prompt caching.');
    }
}
