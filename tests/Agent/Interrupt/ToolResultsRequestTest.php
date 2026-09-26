<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt;

use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ToolResultsRequestTest extends TestCase
{
    protected function request(): ToolResultsRequest
    {
        return (new ToolResultsRequest(
            [2 => new ToolCall('browser', 'pending', ['selector' => '#title'], deferred: true)],
            ['accepted' => ['result' => ['title' => 'Home']]],
        ))->withId(1);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function acceptedResults(): iterable
    {
        yield 'string result' => [['pending' => ['result' => 'Title']]];
        yield 'null result' => [['pending' => ['result' => null]]];
        yield 'false result' => [['pending' => ['result' => false]]];
        yield 'structured result' => [['pending' => ['result' => ['a' => [1, 2]]]]];
        yield 'error outcome' => [['pending' => ['error' => 'User cancelled']]];
        yield 'identical restatement of an accepted result' => [['accepted' => ['result' => ['title' => 'Home']]]];
        yield 'nothing' => [[]];
    }

    /**
     * @param array<array-key, mixed> $results
     */
    #[DataProvider('acceptedResults')]
    public function test_valid_results_are_accepted(array $results): void
    {
        $this->request()->validateResults($results);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function refusedResults(): iterable
    {
        yield 'forged call id' => [['other' => ['result' => 'x']], "Tool result 'other' does not belong to this deferred batch."];
        yield 'empty call id' => [['' => ['result' => 'x']], "Tool result '' does not belong to this deferred batch."];
        yield 'plain value' => [['pending' => 'Title'], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'empty entry' => [['pending' => []], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'result and error' => [['pending' => ['result' => 'x', 'error' => 'y']], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'non string error' => [['pending' => ['error' => ['code' => 1]]], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'null error' => [['pending' => ['error' => null]], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'unknown key' => [['pending' => ['output' => 'x']], "Tool result 'pending' must contain either 'result' or a string 'error'."];
        yield 'conflicting restatement' => [['accepted' => ['result' => ['title' => 'Changed']]], "Tool result 'accepted' has already been settled with a different outcome."];
        yield 'restated as an error' => [['accepted' => ['error' => 'Failed']], "Tool result 'accepted' has already been settled with a different outcome."];
    }

    /**
     * @param array<array-key, mixed> $results
     */
    #[DataProvider('refusedResults')]
    public function test_invalid_results_are_refused(array $results, string $message): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage($message);

        $this->request()->validateResults($results);
    }

    public function test_a_valid_entry_does_not_excuse_an_invalid_one(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'forged' does not belong to this deferred batch.");

        $this->request()->validateResults(['pending' => ['result' => 'ok'], 'forged' => ['result' => 'x']]);
    }

    public function test_resume_input_is_validated_against_the_batch(): void
    {
        $request = $this->request();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'forged' does not belong to this deferred batch.");

        $request->validate(ResumeInput::event($request, ['forged' => ['result' => 'x']]));
    }

    public function test_it_describes_only_the_calls_still_pending(): void
    {
        $request = $this->request();

        $this->assertSame('tool_results', $request->getEventName());
        $this->assertSame('Waiting for external tool results', $request->getMessage());
        $this->assertSame(['pending'], [$request->getToolCalls()[0]->getCallId()]);
        $this->assertSame(['accepted' => ['result' => ['title' => 'Home']]], $request->getResults());
        $this->assertSame(
            '{"interruptId":1,"type":"wait_for_event","eventName":"tool_results","expiresAt":null,'
            . '"message":"Waiting for external tool results","toolCalls":[{"callId":"pending","name":"browser",'
            . '"description":null,"deferred":true,"inputs":{"selector":"#title"},"result":null,"approval":null,'
            . '"approvalReason":null,"rejectReason":null}]}',
            json_encode($request)
        );
    }
}
