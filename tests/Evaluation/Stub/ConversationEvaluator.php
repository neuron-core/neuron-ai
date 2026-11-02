<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Test evaluator that runs a Conversation, the way an evaluator of a multi-turn
 * agent does, and returns its Trajectory or, with $finalAnswer, its last answer.
 */
class ConversationEvaluator extends BaseEvaluator
{
    /**
     * @param array<int, string> $turns
     */
    public function __construct(
        protected AgentInterface $agent,
        protected array $turns,
        protected bool $finalAnswer = false,
        protected ?UserSimulator $simulator = null,
        protected bool $meterAgent = false,
        protected ?AgentInterface $also = null,
    ) {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([['name' => 'a']]);
    }

    public function run(array $datasetItem): mixed
    {
        if ($this->also instanceof AgentInterface) {
            $this->metered($this->also)->for('also')->chat(new UserMessage('Hi'));
        }

        $conversation = Conversation::make($this->meterAgent ? $this->metered($this->agent) : $this->agent);

        $trajectory = $this->simulator instanceof UserSimulator
            ? $conversation->withUser($this->simulator, maxTurns: 3)->run()
            : $conversation->withTurns($this->turns)->run();

        return $this->finalAnswer ? $trajectory->finalAnswer() : $trajectory;
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
    }
}
