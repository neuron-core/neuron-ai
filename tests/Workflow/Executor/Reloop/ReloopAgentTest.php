<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Reloop;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Executor\Reloop\ReloopWorkflowHandler;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use PHPUnit\Framework\TestCase;
use Reloop\Client;
use Reloop\Testing\FakeTransport;
use function base64_decode;
use function unserialize;

class ReloopAgentTest extends TestCase
{
    use ReplaysReloopSteps;

    public function testSimpleAgentChatCompletesViaReplay(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello from AI!'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $this->replayWithReloop(
            $agent,
            boot: fn (Agent $a): mixed => $a->chat(new UserMessage('Hello'))->run(),
        );

        $state = $agent->resolveState();
        $this->assertSame('Hello from AI!', $state->getMessage()->getContent());
    }

    public function testSendResumeUsesFakeTransport(): void
    {
        $transport = new FakeTransport();
        $transport->willReturn(['status' => 'accepted']);

        $client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/reloop',
            apiKey: 'test-key',
            transport: $transport,
        );

        $request = new ApprovalRequest('test approval');

        ReloopWorkflowHandler::resume($client, 'workflow_123', $request);

        $this->assertCount(1, $transport->sent());

        $payload = $transport->sentPayloads()[0];
        $this->assertSame('workflow/interrupt/workflow_123', $payload['name']);

        $resumed = unserialize(base64_decode((string) $payload['data']['resume']));
        $this->assertInstanceOf(ApprovalRequest::class, $resumed);
    }
}
