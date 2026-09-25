<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes;

use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\RAG\Nodes\ConversationIngestionNode;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\RAG\Nodes\Stub\ConversationAgent;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

class ConversationIngestionNodeTest extends TestCase
{
    protected function agent(FakeVectorStore $store, FakeAIProvider $provider): ConversationAgent
    {
        $agent = ConversationAgent::make(workflowId: 'current-thread');
        $agent->conversationStore = $store;
        $agent->conversationEmbeddings = new FakeEmbeddingsProvider();
        $agent->setAiProvider($provider);
        return $agent;
    }

    #[TestWith(['chat'])]
    #[TestWith(['stream'])]
    #[TestWith(['structured'])]
    public function test_completed_exchange_is_ingested_in_each_mode(string $mode): void
    {
        $store = new FakeVectorStore();
        $answer = $mode === 'structured' ? '{"name":"Ada"}' : 'Hello Ada.';
        $agent = $this->agent($store, new FakeAIProvider(new AssistantMessage($answer)));
        $agentRecord = new \NeuronAI\Tests\Support\ExecutionRecorder($agent);
        $question = new UserMessage('Hello');
        if ($mode === 'stream') {
            iterator_to_array($agent->stream($question));
        } elseif ($mode === 'structured') {
            $this->assertSame('Ada', $agent->structured($question, User::class)->name);
        } else {
            $agent->chat($question);
        }

        $store->assertDocumentCount(1);
        $document = $store->getDocuments()[0];
        $this->assertSame("User: Hello\nAssistant: {$answer}", $document->getContent());
        $this->assertSame(SemanticMemoryRetrieval::SOURCE_TYPE, $document->getSourceType());
        $this->assertSame('current-thread', $document->getSourceName());
        $this->assertNotNull($document->getEmbedding());
        $this->assertSame(WorkflowStatus::Completed, $agentRecord->state->getStatus());

        $agent->resetConversation();
        $this->assertSame([], $agent->getChatHistory()->getMessages());
        $store->assertDocumentCount(1);
    }

    public function test_structured_retry_ingests_only_original_question_and_validated_response(): void
    {
        $store = new FakeVectorStore();
        $agent = $this->agent($store, new FakeAIProvider(
            new AssistantMessage('Invalid output'),
            new AssistantMessage('{"name":"Ada"}'),
        ));
        $agent->structured(new UserMessage('Create a user'), User::class);
        $store->assertDocumentCount(1);
        $store->assertHasDocumentWithContent("User: Create a user\nAssistant: {\"name\":\"Ada\"}");
    }

    public function test_tool_approval_resume_ingests_once_and_excludes_tool_traffic(): void
    {
        $store = new FakeVectorStore();
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $first = $this->agent($store, new FakeAIProvider(new ToolCallMessage(null, [
            new ToolCall('get_weather', 'call-1', ['location' => 'Rome']),
        ])));
        $first->setMessageStore($messages)->setPersistence($persistence);
        $first->addTool(GetWeatherTool::make()->requireApproval());
        $this->assertTrue($first->chat(new UserMessage('Weather?'))->isInterrupted());
        $store->assertNothingStored();

        $second = $this->agent($store, new FakeAIProvider(new AssistantMessage('Sunny.')));
        $second->setPersistence($persistence)->setMessageStore($messages);
        $second->addTool(GetWeatherTool::make()->requireApproval());
        $second->submitApprovalDecisions(['call-1' => 'approve'])->run();
        $store->assertDocumentCount(1);
        $store->assertHasDocumentWithContent("User: Weather?\nAssistant: Sunny.");
    }

    #[TestWith(['before'])]
    #[TestWith(['after'])]
    public function test_output_failure_recovers_without_repeating_inference_or_committed_ingestion(string $boundary): void
    {
        $store = new FakeVectorStore();
        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        $messages = new InMemoryMessageStore();
        $persistence = new InMemoryPersistence();
        $first = $this->agent($store, $provider);
        $first->setMessageStore($messages)->setPersistence($persistence);
        $middleware = new FakeMiddleware();
        if ($boundary === 'before') {
            $middleware->setThrowOnBefore(new RuntimeException('Ingestion failed.'));
        } else {
            $middleware->setThrowOnAfter(new RuntimeException('Ingestion failed.'));
        }
        $first->addMiddleware(ConversationIngestionNode::class, $middleware);
        try {
            $first->chat(new UserMessage('Hello'));
            $this->fail('Expected output failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Ingestion failed.', $error->getMessage());
        }
        $store->assertDocumentCount($boundary === 'before' ? 0 : 1);

        $second = $this->agent($store, $provider);
        $second->setPersistence($persistence)->setMessageStore($messages);
        $this->assertSame(WorkflowStatus::Completed, $second->run()->getStatus());
        $provider->assertCallCount(1);
        $store->assertDocumentCount(1);
        $store->assertHasDocumentWithContent("User: Hello\nAssistant: Hello.");
    }

    public function test_failed_inference_does_not_ingest(): void
    {
        $store = new FakeVectorStore();
        $agent = $this->agent($store, new FakeAIProvider());
        $agent->addMiddleware(InferenceNode::class, (new FakeMiddleware())
            ->setThrowOnBefore(new RuntimeException('Provider failed.')));
        try {
            $agent->chat(new UserMessage('Hello'));
            $this->fail('Expected provider failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Provider failed.', $error->getMessage());
        }
        $store->assertNothingStored();
    }

    public function test_incomplete_stream_does_not_ingest(): void
    {
        $store = new FakeVectorStore();
        $agent = $this->agent($store, new FakeAIProvider(new AssistantMessage('Hello.')));
        $stream = $agent->stream(new UserMessage('Hello'));
        $stream->rewind();
        $store->assertNothingStored();
        unset($stream);
    }

    #[TestWith([null, 'Answer'])]
    #[TestWith(['Question', null])]
    public function test_non_text_exchanges_are_skipped(?string $question, ?string $answer): void
    {
        $store = new FakeVectorStore();
        $state = new AgentState();
        $state->request = new InferenceRequest('Instructions', messages: [new UserMessage($question)]);
        $state->setResponse(new ProviderResponse(new AssistantMessage($answer)));
        $node = new ConversationIngestionNode($store, new FakeEmbeddingsProvider());
        $node(new AgentOutputEvent(), $state, AgentResourcesFactory::make());
        $store->assertNothingStored();
    }
}
