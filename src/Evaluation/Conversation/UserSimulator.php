<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use Throwable;

/**
 * An agent that plays the user — a persona plus a goal — generating each next
 * user message from the conversation so far. Powers the simulated
 * configuration path of a Conversation (withUser).
 *
 * The simulator declares its own stop ("goal satisfied" / "giving up") via
 * structured output; there is no separate referee. It never answers
 * suspensions — in production the user and the approver are different humans,
 * so approvals stay with the Conversation's approval policy.
 */
class UserSimulator extends Agent
{
    protected string $persona = 'An everyday user.';

    protected string $goal = '';

    public function withPersona(string $persona): self
    {
        $this->persona = $persona;
        return $this;
    }

    public function withGoal(string $goal): self
    {
        $this->goal = $goal;
        return $this;
    }

    protected function instructions(): string
    {
        return 'You are role-playing a human USER talking to an AI assistant. You are NOT the assistant.'
            . ' Stay in character, pursue your goal naturally, and keep messages short and conversational'
            . ' — the way a real user types. Decide at every step whether the conversation should continue'
            . ' or stop.';
    }

    /**
     * Generate the next user message from the conversation so far, or null
     * when the simulator decides to stop (goal satisfied or giving up).
     *
     * Each step is stateless: the prompt is self-contained (persona + goal +
     * transcript), so the simulator's own chat history is flushed every call.
     *
     * @throws EvaluationException
     * @throws Throwable
     */
    public function nextTurn(Trajectory $soFar): ?UserMessage
    {
        if ($this->goal === '') {
            throw new EvaluationException('The user simulator has no goal. Configure one with withGoal().');
        }

        $this->getChatHistory()->flushAll();

        /** @var SimulatorOutput $output */
        $output = $this->structured(new UserMessage($this->buildPrompt($soFar)), SimulatorOutput::class);

        if ($output->stop || $output->message === null || $output->message === '') {
            return null;
        }

        return new UserMessage($output->message);
    }

    protected function buildPrompt(Trajectory $soFar): string
    {
        $transcript = $soFar->count() > 0
            ? $soFar->toTranscript()
            : '(the conversation has not started yet — you speak first)';

        return "**Your persona:** {$this->persona}\n\n"
            . "**Your goal:** {$this->goal}\n\n"
            . "**The conversation so far:**\n{$transcript}\n\n"
            . 'Decide your next move: if your goal has been satisfied, or you have decided to give up, stop'
            . ' the conversation. Otherwise write your next message to the assistant, staying in character.';
    }
}
