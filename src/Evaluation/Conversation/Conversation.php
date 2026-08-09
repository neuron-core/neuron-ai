<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Conversation;

use Closure;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Throwable;

use function array_key_exists;
use function get_debug_type;
use function implode;
use function is_string;

/**
 * The live execution helper of the evaluation layer: drives an agent through a
 * multi-turn exchange and returns the recorded Trajectory. You run a
 * Conversation; you evaluate its Trajectory.
 *
 * Suspensions (e.g. tool approval) are answered by the approval policy — a
 * callable playing the human. No policy configured + a suspension = an error:
 * silence is never consent, in evals as in production (ADR 0002).
 *
 * @method static static make(AgentInterface $agent)
 */
class Conversation
{
    use StaticConstructor;

    /**
     * @var array<int, string|UserMessage>
     */
    protected array $turns = [];

    protected ?UserSimulator $user = null;

    protected int $maxTurns = 0;

    protected ?Closure $approvals = null;

    public function __construct(protected AgentInterface $agent)
    {
    }

    /**
     * The scripted configuration path: user turns delivered in order, each one
     * sent only after the previous turn fully completed (including any
     * suspend → decide → resume cycle — the thread stays locked while a
     * decision set is open).
     *
     * @param array<int, string|UserMessage> $turns
     */
    public function withTurns(array $turns): self
    {
        $this->turns = $turns;
        return $this;
    }

    /**
     * The simulated configuration path: a UserSimulator generates each next
     * user message until it declares its goal satisfied (or gives up), or the
     * hard cap is reached. Hitting maxTurns ends the conversation *normally* —
     * whether an unfinished conversation is a failure is the assertions'
     * judgment, not the runner's. The simulator never answers suspensions;
     * approvals stay with the approval policy.
     *
     * @param int $maxTurns Hard cap on user turns — required, no infinite default.
     */
    public function withUser(UserSimulator $user, int $maxTurns): self
    {
        if ($maxTurns < 1) {
            throw new EvaluationException('maxTurns must be at least 1.');
        }

        $this->user = $user;
        $this->maxTurns = $maxTurns;
        return $this;
    }

    /**
     * The approval policy — the callable that plays the approver whenever the
     * agent suspends, at any point in the conversation:
     *
     *     fn (InterruptRequest $request, Trajectory $soFar): array
     *
     * It returns the complete resume payload (for an ApprovalRequest: keyed by
     * callId, 'approve' or ['reject', $reason]). Argument-dependent decisions
     * read the tool arguments from the Trajectory tail.
     */
    public function withApprovals(callable $policy): self
    {
        $this->approvals = $policy(...);
        return $this;
    }

    /**
     * Drive the conversation to completion and return the recorded Trajectory.
     *
     * @throws EvaluationException
     * @throws Throwable
     */
    public function run(): Trajectory
    {
        if ($this->user instanceof UserSimulator && $this->turns !== []) {
            throw new EvaluationException('withTurns() and withUser() are mutually exclusive. Configure one path.');
        }

        if ($this->user instanceof UserSimulator) {
            return $this->runSimulated();
        }

        if ($this->turns === []) {
            throw new EvaluationException(
                'The conversation has nothing to run. Configure a script with withTurns() or a simulator with withUser().'
            );
        }

        foreach ($this->turns as $turn) {
            $this->deliver(is_string($turn) ? new UserMessage($turn) : $turn);
        }

        return Trajectory::fromChatHistory($this->agent->getChatHistory());
    }

    /**
     * @throws EvaluationException
     * @throws Throwable
     */
    protected function runSimulated(): Trajectory
    {
        /** @var UserSimulator $user */
        $user = $this->user;

        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $message = $user->nextTurn(Trajectory::fromChatHistory($this->agent->getChatHistory()));

            if (!$message instanceof UserMessage) {
                break; // the simulator declared its stop
            }

            $this->deliver($message);
        }

        return Trajectory::fromChatHistory($this->agent->getChatHistory());
    }

    /**
     * Send one user turn to the agent and drive it to completion, answering
     * any suspensions along the way — identical in both configuration paths.
     *
     * @throws EvaluationException
     * @throws Throwable
     */
    protected function deliver(UserMessage $message): void
    {
        $handler = $this->agent->chat($message);
        $handler->run();

        $this->resolveInterrupts($handler);
    }

    /**
     * Answer suspensions until the current turn completes. A resume may
     * legitimately suspend again (a later gated tool call) and re-enters the
     * loop; termination rides on the agent's own tool-run limits.
     *
     * @throws EvaluationException
     * @throws Throwable
     */
    protected function resolveInterrupts(AgentHandler $handler): void
    {
        while ($handler->interrupted()) {
            /** @var InterruptRequest $request */
            $request = $handler->getInterruptRequest();

            if (!$this->approvals instanceof Closure) {
                throw new EvaluationException(
                    'The agent suspended (' . get_debug_type($request) . ') but no approval policy is configured.'
                    . ' Configure one with withApprovals() — silence is never consent.'
                );
            }

            $payload = ($this->approvals)(
                $request,
                Trajectory::fromChatHistory($this->agent->getChatHistory())
            );

            if ($request instanceof ApprovalRequest) {
                $this->assertCompleteDecisionSet($request, $payload);
            }

            $handler = $this->agent->wake($payload);
            $handler->run();
        }
    }

    /**
     * An incomplete decision set would re-suspend the workflow (ADR 0002) and
     * loop the runner forever — validate before resuming.
     *
     * @param array<string, mixed> $payload
     * @throws EvaluationException
     */
    protected function assertCompleteDecisionSet(ApprovalRequest $request, array $payload): void
    {
        $missing = [];
        foreach ($request->getActions() as $action) {
            if ($action->isPending() && !array_key_exists($action->id, $payload)) {
                $missing[] = "{$action->name} ({$action->id})";
            }
        }

        if ($missing !== []) {
            throw new EvaluationException(
                'The approval policy returned an incomplete decision set — missing decisions for: '
                . implode(', ', $missing) . '. An incomplete set would re-suspend the workflow (ADR 0002).'
            );
        }
    }
}
