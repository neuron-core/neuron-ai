<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\StrictApprovalTool;
use PHPUnit\Framework\TestCase;

class TrackByInputsRunKeyTest extends TestCase
{
    public function test_argument_order_does_not_change_the_run_key(): void
    {
        $tool = new StrictApprovalTool();

        $this->assertSame(
            $tool->setInputs(['permanent' => true, 'account_id' => 1])->getRunKey(),
            $tool->setInputs(['account_id' => 1, 'permanent' => true])->getRunKey()
        );
    }

    public function test_undeclared_arguments_do_not_change_the_run_key(): void
    {
        $tool = new StrictApprovalTool();

        $this->assertSame(
            $tool->setInputs(['permanent' => true, 'account_id' => 1])->getRunKey(),
            $tool->setInputs(['permanent' => true, 'account_id' => 1, 'nonce' => 42])->getRunKey()
        );
    }

    public function test_different_declared_arguments_get_different_run_keys(): void
    {
        $tool = new StrictApprovalTool();

        $this->assertNotSame(
            $tool->setInputs(['permanent' => true, 'account_id' => 1])->getRunKey(),
            $tool->setInputs(['permanent' => true, 'account_id' => 2])->getRunKey()
        );
    }

    public function test_the_same_arguments_spelled_differently_share_the_run_key(): void
    {
        $tool = new StrictApprovalTool();

        $this->assertSame(
            $tool->setInputs(['permanent' => true, 'account_id' => 1])->getRunKey(),
            $tool->setInputs(['permanent' => 'true', 'account_id' => '1'])->getRunKey()
        );
    }
}
