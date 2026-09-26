<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Dataset;

use NeuronAI\Evaluation\Dataset\ArrayDataset;
use PHPUnit\Framework\TestCase;

class ArrayDatasetTest extends TestCase
{
    public function test_load_returns_the_items_with_their_keys(): void
    {
        $items = [3 => ['input' => 'c'], 7 => ['input' => 'g']];

        $this->assertSame($items, (new ArrayDataset($items))->load());
    }
}
