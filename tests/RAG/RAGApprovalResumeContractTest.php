<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use Closure;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * A RAG turn that pauses for a tool approval resumes in another process with
 * the context it retrieved before the pause: the retrieval pipeline is a
 * completed durable step, so the knowledge base is not searched again and a
 * change to it cannot alter the answer being completed.
 */
class RAGApprovalResumeContractTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/neuron_rag_resume_contract_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (['/runs', '/history'] as $subdirectory) {
            foreach (glob($this->directory.$subdirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->directory.$subdirectory)) {
                rmdir($this->directory.$subdirectory);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @return array<string, array{Closure(string): (Closure(): PersistenceInterface)}>
     */
    public static function backends(): array
    {
        return [
            'in-memory' => [static function (string $directory): Closure {
                $persistence = new InMemoryPersistence();

                return static fn (): PersistenceInterface => $persistence;
            }],
            'file' => [static fn (string $directory): Closure => static fn (): PersistenceInterface => new FilePersistence($directory.'/runs')],
        ];
    }

    protected function rag(FakeAIProvider $provider, FakeVectorStore $knowledge, PersistenceInterface $persistence): RAG
    {
        return RAG::make(workflowId: 'rag-thread')
            ->setAiProvider($provider)
            ->setInstructions('Answer from the context.')
            ->setEmbeddingsProvider(new FakeEmbeddingsProvider())
            ->setVectorStore($knowledge)
            ->setPersistence($persistence)
            ->setMessageStore(new FileMessageStore($this->directory.'/history'))
            ->addTool((new SearchTool())->requireApproval());
    }

    /**
     * @param Closure(string): (Closure(): PersistenceInterface) $prepareBackend
     */
    #[DataProvider('backends')]
    public function test_the_resumed_inference_keeps_the_context_retrieved_before_the_pause(Closure $prepareBackend): void
    {
        $connect = $prepareBackend($this->directory);
        $pausing = new FakeAIProvider(new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'refunds'])]));
        $knowledgeBefore = new FakeVectorStore([(new Document('Refunds take 14 days.'))->setSourceType('policy')->setSourceName('refunds.md')]);

        $state = $this->rag($pausing, $knowledgeBefore, $connect())->chat(new UserMessage('How long do refunds take?'));
        $this->assertTrue($state->isInterrupted());
        $context = $pausing->getRecorded()[0]->systemPrompt?->getContent();
        $this->assertSame(
            "Answer from the context.\n\n<EXTRA-CONTEXT>Source Type: policy\nSource Name: refunds.md\nContent: Refunds take 14 days.\n\n</EXTRA-CONTEXT>",
            $context,
        );

        $resuming = new FakeAIProvider(new AssistantMessage('14 days.'));
        $knowledgeAfter = new FakeVectorStore([(new Document('Refunds take 30 days.'))->setSourceType('policy')->setSourceName('refunds.md')]);
        $final = $this->rag($resuming, $knowledgeAfter, $connect())
            ->submitApprovalDecisions(['call_1' => 'approve'])
            ->run();

        $this->assertSame('14 days.', $final->getMessage()?->getContent());
        $knowledgeAfter->assertSearchCount(0);
        $this->assertSame($context, $resuming->getRecorded()[0]->systemPrompt?->getContent());
        $this->assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class],
            array_map(static fn (object $message): string => $message::class, $resuming->getRecorded()[0]->messages),
        );
    }
}
