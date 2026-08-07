<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class ApprovalStateTest extends TestCase
{
    public function test_default_tool_does_not_require_approval(): void
    {
        $this->assertFalse($this->plainTool('x')->requiresApproval([]));
        $this->assertNull(ToolCall::make('x')->getApprovalState());
    }

    public function test_set_approval_state_approved_clears_reason(): void
    {
        $tool = ToolCall::make('x', description: 'd');
        $tool->setApprovalState(ApprovalState::Approved, 'ignored');

        $this->assertEquals(ApprovalState::Approved, $tool->getApprovalState());
        $this->assertNull($tool->getRejectReason());
    }

    public function test_set_approval_state_rejected_keeps_reason(): void
    {
        $tool = ToolCall::make('x', description: 'd');
        $tool->setApprovalState(ApprovalState::Rejected, 'too risky');

        $this->assertEquals(ApprovalState::Rejected, $tool->getApprovalState());
        $this->assertSame('too risky', $tool->getRejectReason());
    }

    public function test_json_serialized_includes_approval_fields(): void
    {
        $gated = ToolCall::make('gated', description: 'd');
        $gated->setApprovalState(ApprovalState::Rejected, 'nope');
        $gated->setApprovalReason('This action is irreversible');
        $gated->setCallId('c1');

        $plain = ToolCall::make('plain', description: 'd');
        $plain->setCallId('c2');

        $gatedData = $gated->jsonSerialize();
        $plainData = $plain->jsonSerialize();

        $this->assertSame('rejected', $gatedData['approval']);
        $this->assertSame('nope', $gatedData['rejectReason']);
        $this->assertSame('This action is irreversible', $gatedData['approvalReason']);

        $this->assertNull($plainData['approval']);
        $this->assertNull($plainData['rejectReason']);
        $this->assertNull($plainData['approvalReason']);
    }

    public function test_subclass_declares_its_policy(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'transfer_money';

            protected function approvalPolicy(array $inputs): bool
            {
                return ($inputs['amount'] ?? 0) > 100;
            }

            public function __invoke(mixed ...$arguments): mixed
            {
                return null;
            }
        };

        $this->assertTrue($tool->requiresApproval(['amount' => 200]));
        $this->assertFalse($tool->requiresApproval(['amount' => 50]));
        $this->assertFalse($tool->requiresApproval([]));
    }

    public function test_approval_policy_can_return_a_reason_string(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'delete_file';

            protected function approvalPolicy(array $inputs): string
            {
                return 'Deleting files is irreversible';
            }

            public function __invoke(mixed ...$arguments): mixed
            {
                return null;
            }
        };

        $this->assertSame('Deleting files is irreversible', $tool->requiresApproval([]));
    }

    public function test_require_approval_forces_the_gate_in_both_directions(): void
    {
        // Force approval onto a tool whose policy declares false.
        $plain = $this->plainTool('plain');
        $plain->requireApproval();
        $this->assertTrue($plain->requiresApproval([]));

        // Waive a tool whose policy declares true.
        $declaring = $this->declaringTool();
        $this->assertTrue($declaring->requiresApproval([]));
        $declaring->suppressApproval();
        $this->assertFalse($declaring->requiresApproval([]));
    }

    public function test_with_approval_policy_replaces_the_declaration(): void
    {
        $tool = $this->plainTool('transfer_money');
        $tool->withApprovalPolicy(fn (ToolInterface $t): bool|string => ($t->getInputs()['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false);

        $tool->setInputs(['amount' => 500]);
        $this->assertSame('Transfers above $100 require a human sign-off', $tool->requiresApproval($tool->getInputs()));

        $tool->setInputs(['amount' => 50]);
        $this->assertFalse($tool->requiresApproval($tool->getInputs()));
    }

    public function test_last_configured_override_wins(): void
    {
        $tool = $this->plainTool('x');

        $tool->withApprovalPolicy(fn (ToolInterface $t): bool => true);
        $tool->suppressApproval();
        $this->assertFalse($tool->requiresApproval([]), 'suppressApproval() clears an earlier policy callback');

        $tool->requireApproval();
        $tool->withApprovalPolicy(fn (ToolInterface $t): bool => false);
        $this->assertFalse($tool->requiresApproval([]), 'withApprovalPolicy() clears an earlier flat override');
    }

    protected function plainTool(string $name): Tool
    {
        $tool = new class () extends Tool {
            public function __invoke(mixed ...$arguments): mixed
            {
                return null;
            }
        };

        $tool->setName($name)->setDescription('d');

        return $tool;
    }

    protected function declaringTool(): Tool
    {
        return new class () extends Tool {
            protected string $name = 'declaring';

            protected function approvalPolicy(array $inputs): bool
            {
                return true;
            }

            public function __invoke(mixed ...$arguments): mixed
            {
                return null;
            }
        };
    }
}
