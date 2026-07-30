<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

class ApprovalStateTest extends TestCase
{
    public function test_default_tool_does_not_require_approval(): void
    {
        $tool = ToolDefinition::make('x', 'd');

        $this->assertFalse($tool->requiresApproval([]));
        $this->assertNull($tool->getApprovalState());
    }

    public function test_set_approval_state_approved_clears_reason(): void
    {
        $tool = ToolDefinition::make('x', 'd');
        $tool->setApprovalState(ApprovalState::Approved, 'ignored');

        $this->assertEquals(ApprovalState::Approved, $tool->getApprovalState());
        $this->assertNull($tool->getRejectReason());
    }

    public function test_set_approval_state_rejected_keeps_reason(): void
    {
        $tool = ToolDefinition::make('x', 'd');
        $tool->setApprovalState(ApprovalState::Rejected, 'too risky');

        $this->assertEquals(ApprovalState::Rejected, $tool->getApprovalState());
        $this->assertSame('too risky', $tool->getRejectReason());
    }

    public function test_json_serialized_includes_approval_fields(): void
    {
        $gated = ToolDefinition::make('gated', 'd');
        $gated->setApprovalState(ApprovalState::Rejected, 'nope');
        $gated->setApprovalReason('This action is irreversible');
        $gated->setCallId('c1');

        $plain = ToolDefinition::make('plain', 'd');
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

    public function test_subclass_can_override_requires_approval(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'transfer_money';

            public function requiresApproval(array $inputs): bool
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

    public function test_requires_approval_can_return_a_reason_string(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'delete_file';

            public function requiresApproval(array $inputs): string
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
}
