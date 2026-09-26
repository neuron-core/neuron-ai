<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\GcdTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

class GcdToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected GcdTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GcdTool();
    }

    #[DataProvider('divisors')]
    public function test_greatest_common_divisor(array $numbers, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($numbers));
    }

    public static function divisors(): array
    {
        return [
            'two numbers' => [[12, 18], '6'],
            'three numbers' => [[12, 18, 24], '6'],
            'coprime' => [[7, 13], '1'],
            'zero is neutral' => [[0, 5], '5'],
            'all zeros' => [[0, 0], '0'],
            'sign ignored' => [[-12, 18], '6'],
            'large powers of two' => [[4611686018427387904, 3298534883328], '1099511627776'],
            'both negative' => [[-4, -6], '2'],
            'duplicates' => [[9, 9, 9], '9'],
            'int max with itself' => [[PHP_INT_MAX, PHP_INT_MAX], '9223372036854775807'],
            'order does not matter' => [[18, 24, 12], '6'],
        ];
    }

    public function test_rejects_invalid_lists(): void
    {
        $this->assertToolError('Provide at least two integers.', ($this->tool)([7]));
        $this->assertToolError('Provide at least two integers.', ($this->tool)([]));
    }

    public function test_the_framework_rejects_a_non_integer_element(): void
    {
        $this->tool->setInputs(['numbers' => [7, 'a']])->execute();

        $this->assertToolError('Parameter "numbers" element 1 must be of type integer, string given.', $this->tool->getResult());
    }

    public function test_the_framework_casts_numeric_strings(): void
    {
        $this->tool->setInputs(['numbers' => ['12', 18.0]])->execute();

        $this->assertSame('6', $this->tool->getResult());
    }
}
