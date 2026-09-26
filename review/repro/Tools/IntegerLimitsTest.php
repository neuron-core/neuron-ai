<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\GcdTool;
use NeuronAI\Tools\Toolkits\Calculator\LcmTool;
use NeuronAI\Tools\Toolkits\Calculator\PermutationsTool;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

class IntegerLimitsTest extends TestCase
{
    public function test_gcd_with_the_smallest_integer(): void
    {
        $this->assertSame('2', (new GcdTool())([PHP_INT_MIN, 2]));
    }

    public function test_lcm_with_the_smallest_integer(): void
    {
        $this->assertSame('27670116110564327424', (new LcmTool())([PHP_INT_MIN, 3]));
    }

    public function test_permutations_of_the_largest_integer(): void
    {
        $this->assertSame('9223372036854775807', (new PermutationsTool())(PHP_INT_MAX, 1));
    }

    public function test_gcd_bound_from_model_json_through_execute(): void
    {
        $tool = new GcdTool();
        $tool->setInputs(json_decode('{"numbers":[-9223372036854775808, 2]}', true));
        $tool->execute();

        $this->assertSame('2', (string) $tool->getResult());
    }
}
