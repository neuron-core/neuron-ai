<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\StructuredOutput\Stub\EmailMode;
use NeuronAI\Tests\StructuredOutput\Stub\FtpMode;
use NeuronAI\Tests\StructuredOutput\Stub\IntEnum;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Workflow\NodeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StructuredOutputNode only retries on AgentException|DeserializerException:
 * any other throwable from malformed model output aborts the agent run.
 */
class DeserializerTypeMismatchTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedOutputProvider(): array
    {
        return [
            'top level string' => ['"John"', Person::class],
            'top level null' => ['null', Person::class],
            'scalar for object property' => ['{"address": "Rome"}', Person::class],
            'scalar for array property' => ['{"tags": "agent"}', Person::class],
            'scalar items for typed array' => ['{"tags": ["agent"]}', Person::class],
            'scalar items for multi type array' => ['{"modes": ["ftp"]}', MultiTypeHolder::class],
            'numeric string for int backed enum' => ['{"level": "1"}', EnumHolder::class],
            'object for int backed enum' => ['{"level": {"value": 1}}', EnumHolder::class],
        ];
    }

    #[DataProvider('malformedOutputProvider')]
    public function test_malformed_model_output_raises_a_deserializer_exception(string $json, string $class): void
    {
        $this->expectException(DeserializerException::class);

        Deserializer::make()->fromJson($json, $class);
    }

    public function test_structured_output_node_retries_when_model_returns_a_scalar_for_an_object_property(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"owner": "Rome"}'),
            new AssistantMessage('{"owner": {"name": "Alice"}}'),
        );
        $state = new AgentState();
        $state->request = new InferenceRequest(instructions: 'Test');
        $state->request->options->outputClass = OwnerHolder::class;
        $state->request->options->maxRetries = 1;
        $state->request->messages = [new UserMessage('Generate')];

        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $this->assertInstanceOf(
            AgentOutputEvent::class,
            $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make(provider: $provider))
        );
        $provider->assertMethodCallCount('structured', 2);
        $this->assertSame('Alice', $state->get('structured_output')->owner->name);
    }
}

class OwnerHolder
{
    public User $owner;
}

class MultiTypeHolder
{
    #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
    public array $modes;
}

class EnumHolder
{
    public IntEnum $level;
}
