<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use JsonException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;

use function array_values;
use function ksort;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class AwaitToolResultsNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    public function __construct(ChatHistoryInterface $chatHistory)
    {
        $this->chatHistory = $chatHistory;
    }

    /**
     * @throws WorkflowInterrupt
     * @throws WorkflowException
     * @throws JsonException
     */
    public function __invoke(AwaitToolResultsEvent $event, AgentState $state): Generator
    {
        $calls = $event->deferredCalls;
        $results = [];
        $pending = [];

        // Every resume restarts this method with an empty results array.
        // Restore results accepted on earlier resumes so a partial delivery
        // leaves only unanswered calls pending instead of requesting them all again.
        foreach ($calls as $call) {
            $id = $call->getCallId();
            $result = $this->recallMemo('result.' . $id);
            if ($result !== null) {
                $results[$id] = $result;
            } else {
                $pending[$id] = $call;
            }
        }

        while ($pending !== []) {
            $request = new ToolResultsRequest($pending, $results);
            $payload = $this->interrupt($request);
            if ($this->timedOut) {
                $this->timedOut = false;
                $payload = [];
                foreach ($pending as $call) {
                    $payload[$call->getCallId()] = ['error' => 'External tool execution timed out.'];
                }
            }

            $request->validateResults($payload ?? []);
            foreach ($payload ?? [] as $id => $result) {
                $results[$id] = $this->memoize('result.' . $id, fn (): array => $result);
                unset($pending[$id]);
            }
        }

        foreach ($calls as $call) {
            $result = $results[$call->getCallId()];
            $call->setResult(isset($result['error'])
                ? ToolOutput::error($result['error'])
                : (is_string($result['result'])
                    ? $result['result']
                    : json_encode($result['result'], JSON_THROW_ON_ERROR)));
            yield new ToolResultChunk($call);
            $this->emit(new ToolCalled($call));
        }

        $completed = $event->completedCalls + $calls;
        ksort($completed);
        $state->request->messages = [new ToolResultMessage(array_values($completed))];

        return AIInferenceEvent::fromRequest($state->request);
    }
}
