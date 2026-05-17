<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Reloop;

use Closure;
use NeuronAI\Workflow\Executor\Reloop\ReloopWorkflowHandler;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use Reloop\Client;
use Reloop\Context;
use Reloop\Event;
use Reloop\Http\Request;
use Reloop\Step;
use Reloop\StepPendingException;
use Reloop\Task;

use function base64_encode;
use function json_encode;
use function serialize;

trait ReplaysReloopSteps
{
    /**
     * Replay through ReloopTaskHandler directly (in-memory).
     *
     * Simulates the Reloop platform replay loop: each iteration creates a fresh
     * Step with memoized data, invokes the handler, catches StepPendingException,
     * and collects ops for the next replay.
     *
     * When $resumeRequest is provided, WaitForEvent ops are automatically
     * populated with the serialized resume data.
     */
    protected function replayWithReloop(
        Workflow $workflow,
        ?Closure $boot = null,
        ?Closure $executorFactory = null,
        ?InterruptRequest $resumeRequest = null,
    ): void {
        $memoized = [];

        for ($i = 0; $i < 20; $i++) {
            $step = new Step($memoized);
            $context = new Context(
                event: new Event(name: 'test/trigger'),
                step: $step,
                runId: 'test-run-' . $i,
                attempt: 0,
            );

            $handler = new ReloopWorkflowHandler(
                $workflow,
                boot: $boot,
                executorFactory: $executorFactory,
            );

            try {
                $handler($context);
                return;
            } catch (StepPendingException) {
                foreach ($step->getOps() as $op) {
                    if (isset($op['data'])) {
                        $memoized[$op['id']] = ['data' => $op['data']];
                    } elseif (($op['op'] ?? '') === 'WaitForEvent' && $resumeRequest !== null) {
                        $memoized[$op['id']] = [
                            'data' => ['resume' => base64_encode(serialize($resumeRequest))],
                        ];
                    }
                }
            }
        }

        $this->fail('Did not complete within 20 replays');
    }

    /**
     * Replay through the Reloop HTTP Handler (round-trip serialization).
     *
     * Builds Request objects as the Scheduler would, calls Handler::handle(),
     * extracts ops from 206 responses, and replays until 200.
     *
     * When $resumeRequest is provided, WaitForEvent ops are automatically
     * populated with the serialized resume data.
     */
    protected function replayThroughHandler(
        Client $client,
        string $taskId,
        array $eventData = [],
        ?Closure $executorFactory = null,
        ?InterruptRequest $resumeRequest = null,
    ): void {
        $steps = [];

        for ($i = 0; $i < 20; $i++) {
            $payload = json_encode([
                'event' => ['name' => 'test/trigger', 'id' => 'evt-' . $i, 'data' => $eventData, 'ts' => 0],
                'events' => [['name' => 'test/trigger', 'id' => 'evt-' . $i, 'data' => $eventData, 'ts' => 0]],
                'steps' => $steps,
                'ctx' => ['run_id' => 'run-' . $i, 'attempt' => 0, 'disable_immediate_execution' => false],
            ]);

            $request = new Request(
                method: 'POST',
                headers: ['Content-Type' => 'application/json'],
                body: $payload,
                query: ['fnId' => $taskId, 'stepId' => 'step'],
            );

            $response = $client->handler()->handle($request);

            if ($response->statusCode === 200) {
                return;
            }

            if ($response->statusCode === 206) {
                foreach ($response->body as $op) {
                    if (isset($op['data'])) {
                        $steps[$op['id']] = ['data' => $op['data']];
                    } elseif (isset($op['error'])) {
                        $steps[$op['id']] = ['error' => $op['error']];
                    } elseif (($op['op'] ?? '') === 'WaitForEvent' && $resumeRequest !== null) {
                        $steps[$op['id']] = [
                            'data' => ['resume' => base64_encode(serialize($resumeRequest))],
                        ];
                    }
                }
            }
        }

        $this->fail('Did not complete within 20 replays');
    }
}
