# Running a turn in a Messenger worker

SKILL.md ("Background Runs with Messenger") explains the design and the clocks; this reference has the two messages, the handler, and what each situation did when run: Messenger on Redis, a real `messenger:consume` worker, MySQL 8.4 and a local stand-in for the provider. The relay controller that dispatches these messages is in [background-delivery.md](background-delivery.md).

## The messages

```php
namespace App\Message;

/** A new turn. The run ID is minted at dispatch, so every retry finishes the same run. */
final class RunSupportAgent
{
    /** @param array<string, mixed> $input the AG-UI RunAgentInput: the worker seeds its adapter and adds the browser tools from it */
    public function __construct(
        public readonly string $threadId,
        public readonly string $runId,
        public readonly string $message,
        public readonly array $input = [],
    ) {
    }
}
```

```php
namespace App\Message;

/** An AG-UI continuation (approval answers in "resume", or browser tool results) of the suspended run $runId. */
final class ResumeSupportAgent
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public readonly string $threadId,
        public readonly string $runId,
        public readonly array $input,
    ) {
    }
}
```

## The handler

```php
namespace App\MessageHandler;

use App\Message\ResumeSupportAgent;
use App\Message\RunSupportAgent;
use App\Neuron\Agents\SupportAgent;
use App\Neuron\RedisRelay;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class SupportAgentHandler
{
    public function __construct(
        protected SupportAgent $agent,
        protected RedisRelay $relay,
    ) {
    }

    #[AsMessageHandler]
    public function start(RunSupportAgent $message): void
    {
        $agent = $this->agent($message->threadId, $message->runId, $message->input);
        $start = ExecutionRequest::start(
            new AgentStartEvent(messages: [new UserMessage($message->message)], options: new AgentRunOptions(stream: true)),
            runId: $message->runId,
            recoverFailed: true, // a redelivery finishes this run from its last committed step
        );

        try {
            $agent->run($start);
        } catch (RunInFlightException $e) {
            if ($e->runId === $message->runId) {
                $this->redelivered($e);

                return;
            }
            if (!$this->isDead($e)) {
                throw new UnrecoverableMessageHandlingException('Another run holds the thread.', previous: $e);
            }
            // A reserved start never replaces another run: finish the dead one on a copy that streams nowhere, then start.
            $this->agent->for($message->threadId)->run(
                ExecutionRequest::resume(expectedRunId: $e->runId, expectedExecutionAttempt: $e->executionAttempt),
            );
            $agent->run($start);
        }
    }

    #[AsMessageHandler]
    public function resume(ResumeSupportAgent $message): void
    {
        $agent = $this->agent($message->threadId, $message->runId, $message->input);

        $run = $agent->inspect();
        if ($run?->runId === $message->runId && $run->status === WorkflowStatus::Failed && $run->interrupt === null) {
            // A retry after the run failed past the answered interrupt: the answers are in the run, finish it.
            $agent->run(ExecutionRequest::resume(expectedRunId: $run->runId, expectedExecutionAttempt: $run->executionAttempt));

            return;
        }

        // Staged at handling time, from the run as it is now.
        $agent->submitInputs($message->input, new AGUIInputTranslator())->run();
    }

    /** @param array<string, mixed> $input the browser's RunAgentInput */
    protected function agent(string $threadId, string $runId, array $input): SupportAgent
    {
        return $this->agent->for($threadId)
            ->setStreamAdapter(fn (): AGUIAdapter => new AGUIAdapter(
                threadId: $threadId,
                runId: $input['runId'] ?? $runId,
                messages: $input['messages'] ?? [],
                state: $input['state'] ?? [],
            ))
            ->setChannel(fn (): StreamingChannelInterface => $this->relay->publisher($runId))
            ->addFrontendTools((new AGUIInputTranslator())->tools($input));
    }

    /** This message's own run, delivered again: wait for a live worker, or let a suspended run wait for its answer. */
    protected function redelivered(RunInFlightException $e): void
    {
        if ($e->status === WorkflowStatus::Running && $e->leaseExpiresAt !== null) {
            throw new RecoverableMessageHandlingException('The run is still executing.', previous: $e, retryDelay: max(1, $e->leaseExpiresAt - time()) * 1000);
        }
    }

    protected function isDead(RunInFlightException $e): bool
    {
        return $e->status === WorkflowStatus::Failed
            || ($e->status === WorkflowStatus::Running && $e->leaseExpiresAt !== null && $e->leaseExpiresAt <= time());
    }
}
```

- The adapter factory builds a seeded `AGUIAdapter` for every segment, with the client's `runId`, `messages` and `state`: the `MESSAGES_SNAPSHOT` sent at an approval prompt then equals the synchronous endpoint's (compared frame by frame, IDs aside). An unseeded adapter sent `{"type":"MESSAGES_SNAPSHOT","messages":[]}`, and the AG-UI client replaced its transcript with it.
- `addFrontendTools()` offers the browser's tools to the model in the worker too: the model's call was handed to the browser, and the browser's trailing tool message continued the run through the relay endpoint.
- Another message's dead run is finished on `$this->agent->for($threadId)`, a copy with neither adapter nor channel: the relay listening for this message reads only the first segment on its channel, so the recovery must not publish there.

## What the handler did, as run

| Situation | What the handler did |
|---|---|
| Inference after a tool failed once (HTTP 500) | Messenger retried after 1 s; same run ID, attempt 2, tool not repeated, one question in history |
| Worker killed with `kill -9` mid-answer | The redelivery met its own run under the lease, retried after the remaining lease (27 s of a 30 s lease), then recovered and completed it |
| Redelivered while the run waits for approval | Nothing |
| A new turn while an approval is pending | Given up (`UnrecoverableMessageHandlingException`); `PublishRunFailure` told the browser |
| The previous turn failed on every delivery | Finished it on a copy without the channel, then ran: history held the old question, its tool call and result, the recovered answer, then the new turn, and only the new turn reached the relay |
| A refund approved while the payment API threw | The run failed with its approval still pending: each retry staged it again and re-ran the tool. When Messenger gave up, the next turn finished it: the refund executed once, then the new turn ran |
| A new turn while the failed turn's retry was still queued | Finished the failed turn and answered; the queued retry then asked the failed turn's question again, as any redelivery after success does |
| A continuation retried after the approved refund ran and the answer failed | Finished the run instead of staging the answers again (`There is no current interruption to answer`); the refund ran once |
| The same message handled twice after success | Ran the turn twice (`Hi, One., Hi, Two.`): completion deletes the run's records |
