<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\UniqueIdGenerator;
use RuntimeException;

/**
 * Test evaluator with an agent under test it meters on every item, as an
 * evaluator holding one injected agent does, and an optional judge. An item with
 * 'fail' throws from run() after the agent has answered, and an item with
 * 'throw_in_evaluate' throws from evaluate() after the judge has answered.
 */
class MeteredEvaluator extends BaseEvaluator
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        protected AgentInterface $agent,
        protected array $items,
        protected ?AgentInterface $judge = null,
    ) {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset($this->items);
    }

    public function run(array $datasetItem): mixed
    {
        $answer = $this->metered($this->agent)
            ->for(UniqueIdGenerator::generateId('item_'))
            ->chat(new UserMessage((string) $datasetItem['name']))
            ->getMessage()
            ?->getContent();

        if ($datasetItem['fail'] ?? false) {
            throw new RuntimeException("run failed for {$datasetItem['name']}");
        }

        return $answer;
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        if ($this->judge instanceof AgentInterface) {
            $this->assert(new AgentJudge($this->judge, 'Check it', 0.5), $output);
        }

        if ($datasetItem['throw_in_evaluate'] ?? false) {
            throw new RuntimeException("evaluate failed for {$datasetItem['name']}");
        }
    }
}
