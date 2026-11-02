<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Usage;

use NeuronAI\Evaluation\Usage\ModelUsage;
use PHPUnit\Framework\TestCase;

class ModelUsageTest extends TestCase
{
    public function test_merge_keeps_one_record_per_model_and_every_field_is_summed(): void
    {
        $merged = ModelUsage::merge([
            new ModelUsage('OpenAI', 'a', 1, 10, 2, 3, 4, 5),
            new ModelUsage('OpenAI', 'b', 1, 1, 1),
            new ModelUsage('OpenAI', 'a', 2, 20, 4, 6, 8, 10),
        ]);

        $this->assertEquals([new ModelUsage('OpenAI', 'a', 3, 30, 6, 9, 12, 15), new ModelUsage('OpenAI', 'b', 1, 1, 1)], $merged);
    }

    public function test_one_model_name_served_by_two_providers_stays_two_records(): void
    {
        $merged = ModelUsage::merge([new ModelUsage('OpenAI', 'gpt', 1, 10, 2), new ModelUsage('AzureOpenAI', 'gpt', 1, 20, 4)]);

        $this->assertEquals([new ModelUsage('OpenAI', 'gpt', 1, 10, 2), new ModelUsage('AzureOpenAI', 'gpt', 1, 20, 4)], $merged);
    }
}
