<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Conversation;

use Closure;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\StaticConstructor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Resume\ResumeInput;
use Throwable;

use function array_key_exists;
use function get_debug_type;
use function implode;
use function is_string;

/**
 * Drives an agent through a multi-turn exchange and returns the recorded
 * Trajectory. Suspensions are answered by the approval policy — a callable
 * playing the human; a suspension with no policy is an error:
 * silence is never consent, in evals as in production.
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
     * The scripted path: turns delivered in order, each sent only after the
     * previous one fully completed (including any suspend → decide → resume cycle).
     *
     * @param array<int, string|UserMessage> $turns
     */
    public function withTurns(array $turns): self
    {
        $this->turns = $turns;
        return $this;
    }

    /**
     * The simulated path: a UserSimulator generates each user message until it
     * declares its stop or maxTurns is hit — which ends the conversation
     * *normally*; whether unfinished is a failure is the assertions' judgment.
     * The simulator never answers suspensions; approvals stay with the policy.
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
     * The approval policy plays the approver whenever the agent suspends:
     * fn (InterruptRequest $request, Trajectory $soFar): array — returning the
     * complete resume payload (for an ApprovalRequest: keyed by callId,
     * 'approve' or ['reject', $reason]).
     */
    public function withApprovals(callable $policy): self
    {
        $this->approvals = $policy(...);
        return $this;
    }

    /**
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
     * @throws EvaluationException
     * @throws Throwable
     */
    protected function deliver(UserMessage $message): void
    {
        $state = $this->agent->chat($message);

        $this->resolveInterrupts($state);
    }

    /**
     * A resume may legitimately suspend again (a later gated tool call);
     * termination rides on the agent's own tool-run limits.
     *
     * @throws EvaluationException
     * @throws Throwable
     */
    protected function resolveInterrupts(AgentState $state): void
    {
        while ($state->isInterrupted()) {
            /** @var InterruptRequest $request */
            $request = $state->getInterruptRequest();

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

            $request = $state->getInterruptRequest();
            if (!$request instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
                throw new EvaluationException('The interrupted Agent exposed no interrupt request.');
            }

            $state = $this->agent->resume([
                ResumeInput::event($request->getId(), $payload),
            ]);
        }
    }

    /**
     * An incomplete decision set would re-suspend the workflow and
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
                . implode(', ', $missing) . '. An incomplete set would re-suspend the workflow.'
            );
        }
    }
}
