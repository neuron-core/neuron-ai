<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier;

use NeuronAI\Classifier\ProbabilityDistribution;
use PHPUnit\Framework\TestCase;

class ProbabilityDistributionFloatTest extends TestCase
{
    public function test_integer_one_hot_probabilities_are_normalized_to_floats(): void
    {
        $distribution = new ProbabilityDistribution(['a' => 1, 'b' => 0]);

        $this->assertSame(['a' => 1.0, 'b' => 0.0], $distribution->probabilities);
    }

    public function test_mixed_probabilities_are_floats(): void
    {
        $distribution = new ProbabilityDistribution(['a' => 1, 'b' => 0.0]);

        $this->assertSame(['a' => 1.0, 'b' => 0.0], $distribution->probabilities);
    }
}
