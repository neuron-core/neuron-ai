<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\TodoPlanning;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;
use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function strlen;
use function substr;

class WriteTodosToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected const PREFIX = 'Updated to do list to: ';

    public function test_the_model_sees_the_three_statuses(): void
    {
        $item = (new WriteTodosTool())->getInputSchema()['properties']['todos']['items'];

        $this->assertSame('object', $item['type']);
        $this->assertSame(['type' => 'string', 'description' => 'Task description'], $item['properties']['content']);
        $this->assertSame(
            ['type' => 'string', 'description' => 'Current status of the task', 'enum' => ['pending', 'in_progress', 'completed']],
            $item['properties']['status']
        );
        $this->assertSame(['todos'], (new WriteTodosTool())->getRequiredProperties());
    }

    public function test_answers_with_the_whole_list(): void
    {
        $todos = [
            ['content' => 'Design the schema', 'status' => 'completed'],
            ['content' => 'Write the migration', 'status' => 'in_progress'],
            ['content' => 'Deploy', 'status' => 'pending'],
        ];

        $this->assertSame(
            self::PREFIX . '[{"content":"Design the schema","status":"completed"},{"content":"Write the migration","status":"in_progress"},{"content":"Deploy","status":"pending"}]',
            (new WriteTodosTool())($todos)
        );
    }

    public function test_an_empty_list_clears_the_plan(): void
    {
        $this->assertSame(self::PREFIX . '[]', (new WriteTodosTool())([]));
    }

    public function test_content_round_trips_verbatim(): void
    {
        $todos = [['content' => "Caffè ☕ \"quoted\" </script>\nnext line", 'status' => 'pending']];

        $result = (new WriteTodosTool())($todos);

        $this->assertSame($todos, json_decode(substr($result, strlen(self::PREFIX)), true));
    }

    public function test_each_call_answers_only_its_own_list(): void
    {
        $tool = new WriteTodosTool();

        $tool([['content' => 'Old', 'status' => 'pending']]);

        $this->assertSame(self::PREFIX . '[{"content":"New","status":"pending"}]', $tool([['content' => 'New', 'status' => 'pending']]));
    }

    /**
     * @param array<int, mixed> $todos
     */
    #[DataProvider('malformedTodos')]
    public function test_malformed_items_are_reported_with_their_index(array $todos, string $error): void
    {
        $this->assertSame($error, (new WriteTodosTool())($todos));
    }

    public static function malformedTodos(): array
    {
        $valid = ['content' => 'Plan', 'status' => 'pending'];

        return [
            'missing status' => [[['content' => 'Plan']], "Error: Todo at index 0 must have 'content' and 'status' fields."],
            'missing content' => [[$valid, ['status' => 'pending']], "Error: Todo at index 1 must have 'content' and 'status' fields."],
            'null content' => [[['content' => null, 'status' => 'pending']], "Error: Todo at index 0 must have 'content' and 'status' fields."],
            'not an object' => [['Plan the work'], "Error: Todo at index 0 must have 'content' and 'status' fields."],
            'unknown status' => [[$valid, $valid, ['content' => 'Ship', 'status' => 'done']], "Error: Todo at index 2 has invalid status 'done'. Must be one of: pending, in_progress, completed."],
            'status is case sensitive' => [[['content' => 'Ship', 'status' => 'PENDING']], "Error: Todo at index 0 has invalid status 'PENDING'. Must be one of: pending, in_progress, completed."],
            'status is compared strictly' => [[['content' => 'Ship', 'status' => true]], "Error: Todo at index 0 has invalid status '1'. Must be one of: pending, in_progress, completed."],
            'first error wins' => [[['content' => 'A', 'status' => 'x'], ['content' => 'B']], "Error: Todo at index 0 has invalid status 'x'. Must be one of: pending, in_progress, completed."],
        ];
    }

    public function test_the_framework_rejects_a_list_sent_as_a_string(): void
    {
        $tool = (new WriteTodosTool())->setInputs(['todos' => '[{"content":"Plan","status":"pending"}]']);

        $tool->execute();

        $this->assertToolError('Parameter "todos" must be of type array, string given.', $tool->getResult());
    }

    public function test_toolkit_provides_only_write_todos_with_its_guidelines(): void
    {
        $toolkit = TodoPlanningToolkit::make();

        $this->assertSame(['write_todos'], array_map(fn (ToolInterface $tool): string => $tool->getName(), $toolkit->tools()));
        $this->assertStringContainsString('`write_todos`', (string) $toolkit->guidelines());
    }

    public function test_toolkit_guidelines_can_be_replaced(): void
    {
        $this->assertSame('Plan only multi-day work.', TodoPlanningToolkit::make('Plan only multi-day work.')->guidelines());
    }
}
