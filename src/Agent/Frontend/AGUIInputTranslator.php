<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Frontend;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolInputTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use DateTimeImmutable;

use function array_fill_keys;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function time;

class AGUIInputTranslator extends ToolInputTranslator
{
    public function translate(array $payload, InterruptRequest $request): array
    {
        if (array_key_exists('resume', $payload)) {
            // Explicit interrupt answers take precedence over mirrored chat history.
            return $this->translateResume($payload, $request);
        }

        if (!$request instanceof ToolResultsRequest) {
            throw new InputTranslationException('Pending AG-UI interrupts require an explicit resume array.');
        }

        $tools = $this->toolCallIds($request);
        $answers = [];
        foreach ($this->entries($payload, 'messages') as $message) {
            if (($message['role'] ?? null) !== 'tool') {
                continue;
            }
            $callId = $message['toolCallId'] ?? null;
            if (!is_string($callId) || !isset($tools[$callId])) {
                continue;
            }
            if (isset($message['error'])) {
                if (!is_string($message['error'])) {
                    throw new InputTranslationException("Tool call '{$callId}' requires a string error.");
                }
                $result = ['error' => $message['error']];
            } else {
                if (!is_string($message['content'] ?? null)) {
                    throw new InputTranslationException("Tool call '{$callId}' requires string content.");
                }
                // AG-UI content is text, even when it happens to contain JSON.
                $result = ['result' => $message['content']];
            }
            $this->answer($answers, $callId, $result);
        }
        return $this->inputs($request, $answers);
    }

    /**
     * Build the client catalog without attaching it to an agent.
     *
     * @param array<string, mixed> $payload
     * @return list<FrontendTool>
     */
    public function tools(array $payload): array
    {
        $tools = [];
        foreach ($this->entries($payload, 'tools') as $definition) {
            $name = $definition['name'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($definition['description'] ?? null)
                || !is_array($definition['parameters'] ?? null)) {
                throw new InputTranslationException('An AG-UI tool requires name, description and parameters.');
            }
            if (isset($tools[$name])) {
                throw new InputTranslationException("Duplicate frontend tool '{$name}'.");
            }
            $tools[$name] = new FrontendTool($name, $definition['description'], $definition['parameters']);
        }
        return array_values($tools);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function translateResume(array $payload, InterruptRequest $request): array
    {
        $ids = $request instanceof ApprovalRequest
            ? array_map(fn (Action $action): string => $action->id, $request->getActions())
            : [(string) $request->getId()];
        $targets = array_fill_keys($ids, true);

        $answers = [];
        $seen = [];
        foreach ($this->entries($payload, 'resume') as $entry) {
            $id = $entry['interruptId'] ?? null;
            if (!is_string($id) || !isset($targets[$id])) {
                throw new InputTranslationException('The resume entry does not identify an active interrupt.');
            }
            if (isset($seen[$id])) {
                throw new InputTranslationException("Duplicate resume entry '{$id}'.");
            }
            $seen[$id] = true;
            $status = $entry['status'] ?? null;
            if ($status !== 'resolved' && $status !== 'cancelled') {
                throw new InputTranslationException("Interrupt '{$id}' requires resolved or cancelled status.");
            }
            if ($status === 'cancelled' && array_key_exists('payload', $entry)) {
                throw new InputTranslationException('A cancelled resume must omit payload.');
            }
            if ($request instanceof WaitForEventRequest && $request->getExpiresAt() instanceof DateTimeImmutable
                && $request->getExpiresAt()->getTimestamp() <= time()) {
                throw new InputTranslationException("Interrupt '{$id}' has expired.");
            }
            if ($request instanceof ApprovalRequest) {
                $this->answer($answers, $id, $status === 'cancelled' ? 'reject' : $this->approval($entry['payload'] ?? null));
            } elseif ($request instanceof ToolResultsRequest && $status === 'cancelled') {
                foreach ($request->getToolCalls() as $call) {
                    $this->answer($answers, $call->getCallId(), ['error' => 'Frontend tool execution cancelled.']);
                }
            } elseif ($request instanceof WaitForEventRequest && $status === 'resolved') {
                if (!is_array($entry['payload'] ?? null)) {
                    throw new InputTranslationException("Interrupt '{$id}' requires an object payload.");
                }
                $answers = $entry['payload'];
            } else {
                throw new InputTranslationException("Interrupt '{$id}' does not support this resume operation.");
            }
        }
        if (count($seen) !== count($targets)) {
            throw new InputTranslationException('AG-UI resume must address every published interrupt.');
        }
        if ($request instanceof ToolResultsRequest) {
            $request->validateResults($answers);
        }
        return $answers;
    }
}
