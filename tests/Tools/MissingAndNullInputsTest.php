<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

/**
 * What the model leaves out or sets to null never reaches __invoke() as a value its
 * signature can't take: the declared default applies, and a required value is feedback.
 */
class MissingAndNullInputsTest extends TestCase
{
    use RecordsHttpRequests;

    protected function searchTool(): Tool
    {
        return new class () extends Tool {
            protected string $name = 'search';

            protected function properties(): array
            {
                return [
                    new ToolProperty('query', PropertyType::STRING, 'Query', true),
                    new ToolProperty('limit', PropertyType::INTEGER, 'Max results'),
                ];
            }

            public function __invoke(string $query, int $limit = 10): string
            {
                return "{$query}:{$limit}";
            }
        };
    }

    protected function nullableLimitTool(): Tool
    {
        return new class () extends Tool {
            protected string $name = 'search';

            protected function properties(): array
            {
                return [
                    new ToolProperty('query', PropertyType::STRING, 'Query', true),
                    new ToolProperty('limit', PropertyType::INTEGER, 'Max results'),
                ];
            }

            public function __invoke(string $query, ?int $limit = 10): string
            {
                return "{$query}:" . ($limit ?? 'null');
            }
        };
    }

    public function test_an_omitted_optional_input_gets_the_invoke_default(): void
    {
        $tool = $this->searchTool()->setInputs(['query' => 'php']);
        $tool->execute();

        $this->assertSame('php:10', $tool->getResult());
    }

    public function test_a_null_optional_input_gets_the_default_when_the_parameter_cannot_take_null(): void
    {
        $tool = $this->searchTool()->setInputs(['query' => 'php', 'limit' => null]);
        $tool->execute();

        $this->assertSame('php:10', $tool->getResult());
    }

    public function test_an_omitted_optional_input_gets_a_non_null_default_of_a_nullable_parameter(): void
    {
        $tool = $this->nullableLimitTool()->setInputs(['query' => 'php']);
        $tool->execute();

        $this->assertSame('php:10', $tool->getResult());
    }

    public function test_a_null_optional_input_reaches_a_nullable_parameter_as_null(): void
    {
        $tool = $this->nullableLimitTool()->setInputs(['query' => 'php', 'limit' => null]);
        $tool->execute();

        $this->assertSame('php:null', $tool->getResult());
    }

    public function test_zep_search_without_the_optional_scope_searches_facts(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(200, [], (string) json_encode(['uuid' => 'user-1'])),
            new Response(200, [], (string) json_encode(['edges' => []])),
        ));

        $tool->setInputs(['query' => 'favourite language'])->execute();

        $this->assertSame(
            ['user_id' => 'user-1', 'query' => 'favourite language', 'scope' => 'edges', 'limit' => 5],
            json_decode((string) $this->sentRequests[1]['request']->getBody(), true)
        );
    }

    /**
     * @return array<string, array{ToolInterface, array<string, mixed>, string}>
     */
    public static function requiredInputsWithoutAValue(): array
    {
        return [
            'null integer' => [new FactorialTool(), ['n' => null], 'Parameter "n" must be of type integer, null given.'],
            'null array' => [new WriteTodosTool(), ['todos' => null], 'Parameter "todos" must be of type array, null given.'],
            'omitted integer' => [new FactorialTool(), [], 'Parameter "n" is required.'],
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     */
    #[DataProvider('requiredInputsWithoutAValue')]
    public function test_a_required_input_without_a_value_is_feedback_and_needs_no_approval(ToolInterface $tool, array $inputs, string $feedback): void
    {
        $tool->setInputs($inputs);

        $this->assertFalse($tool->requiresApproval());

        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame($feedback, $result->getText());
    }

    public function test_null_for_a_required_nullable_input_reaches_invoke(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'nullable_tool';

            protected function properties(): array
            {
                return [new ToolProperty('value', PropertyType::INTEGER, required: true, nullable: true)];
            }

            public function __invoke(?int $value): string
            {
                return $value === null ? 'null' : (string) $value;
            }
        };

        $tool->setInputs(['value' => null])->execute();

        $this->assertSame('null', $tool->getResult());
    }

    public function test_null_for_an_optional_input_with_a_null_default_reaches_invoke(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'optional_tool';

            protected function properties(): array
            {
                return [new ToolProperty('value', PropertyType::INTEGER)];
            }

            public function __invoke(?int $value = null): string
            {
                return $value === null ? 'null' : (string) $value;
            }
        };

        $tool->setInputs(['value' => null])->execute();

        $this->assertSame('null', $tool->getResult());
    }
}
