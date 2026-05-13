<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Skills;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\Skills\AbstractSkill;
use NeuronAI\Agent\Skills\MarkdownSkill;
use NeuronAI\Agent\Skills\SkillInterface;
use NeuronAI\Agent\Skills\SkillLoader;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use PHPUnit\Framework\TestCase;

class SkillIntegrationTest extends TestCase
{
    private string $toolsFixturesPath;
    private string $fixturesPath;
    private string $overrideFixturesPath;

    protected function setUp(): void
    {
        $this->toolsFixturesPath = __DIR__.'/../fixtures/skills-tools';
        $this->fixturesPath = __DIR__.'/../fixtures/skills';
        $this->overrideFixturesPath = __DIR__.'/../fixtures/skills-override';
    }

    // --- Tier 1: Skill disclosure in system prompt ---

    public function test_skill_with_instructions_and_tools(): void
    {
        $searchTool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(fn (string $query): string => "Results for: {$query}");

        $skill = new class($searchTool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'web-search';
            }

            public function instructions(): string
            {
                return 'You have access to web search. Always cite your sources.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Here are the results.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Search for PHP frameworks'))->getMessage();

        $provider->assertCallCount(1);
        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '# web-search')
            && str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: web-search]')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>')
            && !str_contains($record->systemPrompt ?? '', 'You have access to web search'));
    }

    public function test_skill_with_only_instructions(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'french-mode';
            }

            public function instructions(): string
            {
                return 'Always respond in French.';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Bonjour!')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Hello'))->getMessage();

        $provider->assertCallCount(1);
        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'Always respond in French.')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_skill_with_only_tools(): void
    {
        $tool = Tool::make('calculate', 'Calculate something')
            ->addProperty(new ToolProperty('expression', PropertyType::STRING, 'Math expression', true))
            ->setCallable(fn (string $expr): string => "Result: {$expr}");

        $skill = new class($tool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'calculator';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Calculated!')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Calculate 2+2'))->getMessage();

        $provider->assertCallCount(1);
        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: calculator]')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_empty_skill_no_side_effects(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'empty-skill';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('Base instructions.');
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            $record->systemPrompt === 'Base instructions.');
    }

    public function test_skill_instructions_not_duplicated(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'dedup-skill';
            }

            public function instructions(): string
            {
                return 'Unique instruction.';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('Base.');
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>')
            && substr_count($record->systemPrompt ?? '', '<SKILL-GUIDELINES>') === 1);
    }

    public function test_no_body_skill_includes_description_in_tier1(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill(new MarkdownSkill($this->fixturesPath.'/no-body'));
        $agent->setInstructions('Base instructions.');

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>')
            && str_contains($record->systemPrompt ?? '', 'no-body: A skill with only frontmatter and no body content'));
    }

    public function test_compose_system_prompt_order(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'test-skill';
            }

            public function instructions(): string
            {
                return 'Skill instructions here.';
            }

            public function tools(): array
            {
                return [new CalculatorToolkit()];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('Base agent instructions.');
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(function (RequestRecord $record): bool {
            $prompt = $record->systemPrompt ?? '';
            $basePos = strpos($prompt, 'Base agent instructions.');
            $skillPos = strpos($prompt, '<SKILL-GUIDELINES>');

            return $basePos !== false
                && $skillPos !== false
                && $basePos < $skillPos;
        });
    }

    // --- Tier 1: Multiple skills ---

    public function test_multiple_skills(): void
    {
        $skillA = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'skill-a';
            }

            public function instructions(): string
            {
                return 'Instruction A';
            }
        };

        $skillB = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'skill-b';
            }

            public function instructions(): string
            {
                return 'Instruction B';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Done')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skillA);
        $agent->addSkill($skillB);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '# skill-a')
            && str_contains($record->systemPrompt ?? '', 'Instruction A')
            && str_contains($record->systemPrompt ?? '', '# skill-b')
            && str_contains($record->systemPrompt ?? '', 'Instruction B'));
    }

    public function test_skill_priority_ordering(): void
    {
        $skillLow = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'low-priority';
            }

            public function priority(): int
            {
                return 10;
            }

            public function instructions(): string
            {
                return 'Low priority instruction';
            }
        };

        $skillHigh = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'high-priority';
            }

            public function priority(): int
            {
                return -5;
            }

            public function instructions(): string
            {
                return 'High priority instruction';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill([$skillLow, $skillHigh]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'high-priority')
            && str_contains($record->systemPrompt ?? '', 'low-priority')
            && strpos($record->systemPrompt ?? '', 'high-priority') < strpos($record->systemPrompt ?? '', 'low-priority'));
    }

    // --- Tier 1: Skill basics ---

    public function test_abstract_skill_defaults(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'default-skill';
            }
        };

        $this->assertSame('default-skill', $skill->name());
        $this->assertSame('', $skill->description());
        $this->assertSame(0, $skill->priority());
        $this->assertNull($skill->instructions());
        $this->assertNull($skill->trigger());
        $this->assertSame([], $skill->tools());
    }

    public function test_add_skill_accepts_array(): void
    {
        $skillA = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'skill-a';
            }
        };

        $skillB = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'skill-b';
            }
        };

        $agent = Agent::make();
        $agent->addSkill([$skillA, $skillB]);

        $this->assertCount(2, $agent->getSkills());
    }

    public function test_skills_override_in_subclass(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'inherited-skill';
            }

            public function instructions(): string
            {
                return 'Inherited instructions';
            }
        };

        $agent = new class($skill) extends Agent
        {
            public function __construct(private SkillInterface $skill)
            {
                parent::__construct();
            }

            protected function skills(): array
            {
                return [$this->skill];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );
        $agent->setAiProvider($provider);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'Inherited instructions'));
    }

    public function test_skill_configure_modifies_agent(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'configurer';
            }

            public function configure(AgentInterface $agent): void
            {
                $agent->setInstructions('Injected by skill configure.');
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Configured!')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'Injected by skill configure.'));
    }

    public function test_skill_providing_toolkit(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'math';
            }

            public function instructions(): string
            {
                return 'You can do math operations.';
            }

            public function tools(): array
            {
                return [new CalculatorToolkit()];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Calculated!')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($skill);

        $agent->chat(new UserMessage('What is 2+2?'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>')
            && str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: math]'));
    }

    // --- Tier 2: Lazy activation ---

    public function test_skill_tools_not_in_initial_request(): void
    {
        $tool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(fn (string $query): string => "Results for: {$query}");

        $skill = new class($tool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'web-search';
            }

            public function instructions(): string
            {
                return 'You have access to web search.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertCallCount(1);
        $provider->assertSent(fn (RequestRecord $record): bool =>
            !in_array('search', array_map(fn (ToolInterface $t): string => $t->getName(), $record->tools))
        );
    }

    public function test_skill_tools_injected_after_activation_marker(): void
    {
        $tool = Tool::make('weather', 'Get weather')
            ->addProperty(new ToolProperty('city', PropertyType::STRING, 'City name', true))
            ->setCallable(fn (string $city): string => "Sunny in {$city}");

        $skill = new class($tool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'weather';
            }

            public function instructions(): string
            {
                return 'You can check weather.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Checking... [ACTIVATE_SKILL: weather]'),
            new AssistantMessage('The weather is sunny.')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill($skill);

        $agent->chat(new UserMessage('Weather?'))->getMessage();

        $provider->assertCallCount(2);

        $provider->assertSent(function (RequestRecord $record): bool {
            $toolNames = array_map(fn (ToolInterface $t): string => $t->getName(), $record->tools);
            return in_array('weather', $toolNames);
        });
    }

    public function test_multiple_skills_activated_independently(): void
    {
        $toolA = Tool::make('search', 'Search')
            ->addProperty(new ToolProperty('q', PropertyType::STRING, 'Query', true))
            ->setCallable(fn (string $q): string => "Results: {$q}");

        $toolB = Tool::make('translate', 'Translate')
            ->addProperty(new ToolProperty('text', PropertyType::STRING, 'Text', true))
            ->setCallable(fn (string $text): string => "Translated: {$text}");

        $skillA = new class($toolA) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'search';
            }

            public function instructions(): string
            {
                return 'Search the web.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $skillB = new class($toolB) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'translate';
            }

            public function instructions(): string
            {
                return 'Translate text.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Searching... [ACTIVATE_SKILL: search]'),
            new AssistantMessage('Translating... [ACTIVATE_SKILL: translate]'),
            new AssistantMessage('All done.')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill([$skillA, $skillB]);

        $agent->chat(new UserMessage('Search and translate'))->getMessage();

        $provider->assertCallCount(3);

        $provider->assertSent(function (RequestRecord $record): bool {
            $toolNames = array_map(fn (ToolInterface $t): string => $t->getName(), $record->tools);
            return in_array('search', $toolNames);
        });

        $provider->assertSent(function (RequestRecord $record): bool {
            $toolNames = array_map(fn (ToolInterface $t): string => $t->getName(), $record->tools);
            return in_array('translate', $toolNames);
        });
    }

    public function test_full_activation_and_tool_call_flow(): void
    {
        $tool = Tool::make('weather', 'Get weather')
            ->addProperty(new ToolProperty('city', PropertyType::STRING, 'City name', true))
            ->setCallable(fn (string $city): string => "Sunny in {$city}");

        $skill = new class($tool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'weather';
            }

            public function instructions(): string
            {
                return 'Check weather.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Let me check. [ACTIVATE_SKILL: weather]'),
            new ToolCallMessage(null, [
                (clone $tool)->setCallId('call_1')->setInputs(['city' => 'Paris']),
            ]),
            new AssistantMessage('The weather in Paris is sunny.')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill($skill);

        $message = $agent->chat(new UserMessage('Weather in Paris?'))->getMessage();

        $this->assertSame('The weather in Paris is sunny.', $message->getContent());
        $provider->assertCallCount(3);
    }

    public function test_skill_without_tools_has_no_activation_hint(): void
    {
        $skill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'french';
            }

            public function instructions(): string
            {
                return 'Always respond in French.';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('Bonjour!')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill($skill);

        $response = $agent->chat(new UserMessage('Hello'));
        $content = $response->getMessage()->getContent();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            !str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: french]')
            && str_contains($record->systemPrompt ?? '', 'Always respond in French.')
        );
        $this->assertSame('Bonjour!', $content);
    }

    public function test_skill_with_only_tools_has_activation_hint(): void
    {
        $tool = Tool::make('calculate', 'Calculate')
            ->addProperty(new ToolProperty('expr', PropertyType::STRING, 'Expression', true))
            ->setCallable(fn (string $expr): string => "Result: {$expr}");

        $skill = new class($tool) extends AbstractSkill
        {
            public function __construct(private ToolInterface $tool) {}

            public function name(): string
            {
                return 'calculator';
            }

            public function tools(): array
            {
                return [$this->tool];
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkill($skill);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: calculator]')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>')
        );
    }

    // --- Activation edge cases ---

    public function test_activate_nonexistent_skill_returns_false(): void
    {
        $agent = Agent::make();
        $this->assertFalse($agent->activateSkill('nonexistent'));
    }

    // --- Script skill integration (MarkdownSkill + DeclarativeToolBuilder) ---

    public function test_agent_add_skill_directory_injects_activation_hints(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillDirectory([$this->toolsFixturesPath]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: api-health-check]')
            && str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: csv-transformer]')
            && str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: log-error-analyzer]')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_agent_add_skill_paths_injects_activation_hints(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillPaths([
                $this->toolsFixturesPath.'/csv-transformer',
                $this->toolsFixturesPath.'/log-error-analyzer',
            ]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: csv-transformer]')
            && str_contains($record->systemPrompt ?? '', '[ACTIVATE_SKILL: log-error-analyzer]')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_markdown_skill_no_tools_registered(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill(new MarkdownSkill($this->fixturesPath.'/web-search'));

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            $record->tools === []);
    }

    public function test_agent_add_skill_directory_includes_description(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addSkillDirectory([$this->fixturesPath]);

        $agent->chat(new UserMessage('Search for PHP'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'web-search')
            && str_contains($record->systemPrompt ?? '', 'Use the search tool')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_agent_add_skill_directory_multiple(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkillDirectory([$this->fixturesPath, $this->overrideFixturesPath]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'french-translator')
            && str_contains($record->systemPrompt ?? '', 'Overridden Web Search'));
    }

    public function test_agent_add_skill_directory_with_priority_ordering(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkillDirectory([$this->fixturesPath]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            strpos($record->systemPrompt ?? '', 'with-metadata') < strpos($record->systemPrompt ?? '', 'web-search'));
    }

    public function test_mixed_php_and_markdown_skills(): void
    {
        $phpSkill = new class extends AbstractSkill
        {
            public function name(): string
            {
                return 'php-skill';
            }

            public function instructions(): string
            {
                return 'PHP skill instructions.';
            }
        };

        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkill($phpSkill);
        $agent->addSkillDirectory([$this->fixturesPath]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'php-skill')
            && str_contains($record->systemPrompt ?? '', 'web-search')
            && str_contains($record->systemPrompt ?? '', 'PHP skill instructions.'));
    }

    public function test_agent_add_skill_directory_override(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkillDirectory([$this->fixturesPath, $this->overrideFixturesPath]);

        $agent->chat(new UserMessage('Search'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'overridden web search skill')
            && !str_contains($record->systemPrompt ?? '', 'Use the search tool'));
    }

    public function test_agent_add_skill_paths(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkillPaths([
            $this->fixturesPath.'/web-search',
            $this->fixturesPath.'/french-translator',
        ]);

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'web-search')
            && str_contains($record->systemPrompt ?? '', 'french-translator')
            && str_contains($record->systemPrompt ?? '', '<SKILL-GUIDELINES>'));
    }

    public function test_agent_add_skill_paths_override(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('OK')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addSkillPaths([
            $this->fixturesPath.'/web-search',
            $this->overrideFixturesPath.'/web-search',
        ]);

        $agent->chat(new UserMessage('Search'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool =>
            str_contains($record->systemPrompt ?? '', 'overridden web search skill')
            && !str_contains($record->systemPrompt ?? '', 'Use the search tool'));
    }

}
