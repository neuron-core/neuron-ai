<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class DuplicateCallIdWeatherTool extends Tool
{
    /** @var string[] */
    public static array $executions = [];

    protected string $name = 'get_weather';

    protected ?string $description = 'Get the weather for a city.';

    protected function properties(): array
    {
        return [
            new ToolProperty('city', PropertyType::STRING, 'The city', true),
        ];
    }

    public function __invoke(string $city): string
    {
        self::$executions[] = $city;
        return "Weather in {$city}: sunny";
    }
}

/**
 * Regression test: providers without per-call ids (Gemini historically reused the
 * tool name as callId) can hand the ToolNode parallel calls sharing a callId. The
 * durable memo key must still be unique per call, or the second call is silently
 * skipped and handed the first call's result.
 */
class DuplicateCallIdToolExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        DuplicateCallIdWeatherTool::$executions = [];
    }

    public function test_parallel_calls_sharing_a_call_id_both_execute(): void
    {
        $registered = new DuplicateCallIdWeatherTool();

        $first = ToolCall::make($registered->getName(), 'get_weather', ['city' => 'Rome']);
        $second = ToolCall::make($registered->getName(), 'get_weather', ['city' => 'Milan']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$first, $second]),
            new AssistantMessage('done'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->addTool($registered);

        $agent->chat(new UserMessage('Weather in Rome and Milan?'))->getMessage();

        $this->assertSame(['Rome', 'Milan'], DuplicateCallIdWeatherTool::$executions);

        $results = [];
        foreach ($agent->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $results[] = $tool->getResult();
                }
            }
        }

        $this->assertSame(
            ['Weather in Rome: sunny', 'Weather in Milan: sunny'],
            $results
        );
    }
}
