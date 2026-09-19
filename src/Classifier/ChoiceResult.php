<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function array_diff_key;
use function array_key_first;
use function count;

class ChoiceResult
{
    public readonly string $choice;
    public readonly ProbabilityDistribution $distribution;

    /**
     * @param array<string, int|float> $probabilities Probability for every option in the definition.
     */
    public function __construct(
        public readonly Choice $definition,
        array $probabilities,
    ) {
        if (count($probabilities) !== count($definition->options)
            || array_diff_key($definition->options, $probabilities) !== []) {
            throw new InvalidArgumentException('Choice probabilities must cover exactly the defined options.');
        }

        $this->distribution = new ProbabilityDistribution($probabilities);
        $choice = array_key_first($definition->options);

        // Definition order resolves ties independently of the provider's response order.
        foreach ($definition->options as $id => $description) {
            if ($this->distribution->probabilities[$id] > $this->distribution->probabilities[$choice]) {
                $choice = $id;
            }
        }

        $this->choice = $choice;
    }
}
