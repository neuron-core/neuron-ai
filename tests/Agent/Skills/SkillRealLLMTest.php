<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Skills;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\SkillsBootstrapped;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use PHPUnit\Framework\TestCase;

class SkillRealLLMTest extends TestCase
{
    private string $toolsFixturesPath;

    private object $eventRecorder;

    protected function tearDown(): void
    {
        EventBus::clear();
    }

    protected function setUp(): void
    {
        $this->toolsFixturesPath = __DIR__.'/../fixtures/skills-tools';
    }

    private function createProviderFromEnv(): ?OpenAI
    {
        $apiKey = getenv('OPENAI_API_KEY');
        $baseUrl = getenv('OPENAI_BASE_URL');

        if ($apiKey === false || $apiKey === '' || $baseUrl === false || $baseUrl === '') {
            return null;
        }

        $model = getenv('OPENAI_MODEL') ?: 'gpt-4o';

        return new class($apiKey, $model, $baseUrl) extends OpenAI
        {
            protected string $baseUri;

            public function __construct(string $key, string $model, string $baseUrl)
            {
                $this->baseUri = rtrim($baseUrl, '/').'/';
                parent::__construct($key, $model);
            }
        };
    }

    /**
     * Register an event recorder observer.
     * After the agent runs, read events from $this->eventRecorder->events.
     */
    private function captureEvents(string $workflowId): void
    {
        $this->eventRecorder = new class implements ObserverInterface
        {
            public array $events = [];

            public function onEvent(string $event, object $source, mixed $data = null): void
            {
                $this->events[$event][] = $data;
            }
        };

        EventBus::observe($this->eventRecorder, $workflowId);
    }

    public function test_agent_with_script_skills_using_real_llm(): void
    {
        $provider = $this->createProviderFromEnv();
        if ($provider === null) {
            $this->markTestSkipped('OPENAI_API_KEY and OPENAI_BASE_URL must be set. Skipping real LLM test.');
        }

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillDirectory([$this->toolsFixturesPath]);

        assert($agent instanceof Agent);
        $this->captureEvents($agent->getWorkflowId());

        $response = $agent->chat(
            new UserMessage('What capabilities do you have? Describe each skill briefly.')
        );

        $content = $response->getMessage()->getContent();
        $this->assertNotEmpty($content);

        // Framework-level assertions: verify skill bootstrapping happened
        $events = $this->eventRecorder->events;

        $bootstrapped = $events['skills-bootstrapped'] ?? [];
        $this->assertCount(1, $bootstrapped, 'Expected exactly one skills-bootstrapped event');
        $this->assertNotEmpty($bootstrapped[0]->skills, 'Expected skills to be bootstrapped');

        $skillNames = array_map(fn ($s) => $s->name(), $bootstrapped[0]->skills);
        $this->assertContains('api-health-check', $skillNames);
        $this->assertContains('csv-transformer', $skillNames);
        $this->assertContains('get-weather', $skillNames);
        $this->assertContains('shipping-calculator', $skillNames);

        // LLM should describe the skills without activating them
        $this->assertEmpty($events['skill-activated'] ?? [], 'No skills should be activated for a capability inquiry');
    }

    public function test_agent_with_skill_paths_using_real_llm(): void
    {
        $provider = $this->createProviderFromEnv();
        if ($provider === null) {
            $this->markTestSkipped('OPENAI_API_KEY and OPENAI_BASE_URL must be set. Skipping real LLM test.');
        }

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillPaths([
                $this->toolsFixturesPath.'/csv-transformer',
                $this->toolsFixturesPath.'/log-error-analyzer',
            ]);

        assert($agent instanceof Agent);
        $this->captureEvents($agent->getWorkflowId());

        $response = $agent->chat(
            new UserMessage('What can you help me with?')
        );

        $content = $response->getMessage()->getContent();
        $this->assertNotEmpty($content);

        $events = $this->eventRecorder->events;

        // Verify only the 2 specified skills were bootstrapped (not the whole directory)
        $bootstrapped = $events['skills-bootstrapped'] ?? [];
        $this->assertCount(1, $bootstrapped);
        $skillNames = array_map(fn ($s) => $s->name(), $bootstrapped[0]->skills);
        $this->assertContains('csv-transformer', $skillNames);
        $this->assertContains('log-error-analyzer', $skillNames);
        $this->assertCount(2, $skillNames, 'Only 2 skills should be registered via addSkillPaths');

        // Capability inquiry should not trigger activation
        $this->assertEmpty($events['skill-activated'] ?? []);
    }

    /**
     * Multi-skill activation and tool execution test.
     *
     * Run with NEURON_DEBUG=true to observe the full LLM interaction cycle:
     *   - Tier 1 skill disclosure in LLM CALL #1
     *   - [ACTIVATE_SKILL] markers triggering skill activation
     *   - Tier 2 instructions + tools injected in LLM CALL #2
     *   - Tool calls and results across multiple inference rounds
     *   - Final LLM summary with all task results
     *
     * This test also serves as a debugging tool — run with:
     *   NEURON_DEBUG=true OPENAI_API_KEY=... OPENAI_BASE_URL=... OPENAI_MODEL=... \
     *     vendor/bin/phpunit --filter test_multi_skill_activation_with_real_tool_calls 2>&1 | tee output.log
     */
    public function test_multi_skill_activation_with_real_tool_calls(): void
    {
        $provider = $this->createProviderFromEnv();
        if ($provider === null) {
            $this->markTestSkipped('OPENAI_API_KEY and OPENAI_BASE_URL must be set. Skipping real LLM test.');
        }

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillDirectory([$this->toolsFixturesPath]);

        $content = $agent->chat(
            new UserMessage('Test if this URL is reachable: https://httpbin.org/get. Also calculate the shipping cost (in CNY) for a 1.6kg package to Japan. And check the current weather in Shenzhen.')
        )->getMessage()->getContent();

        $this->assertNotEmpty($content);
    }

}
