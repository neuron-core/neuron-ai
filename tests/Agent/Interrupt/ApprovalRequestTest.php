<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt;

use DateTimeImmutable;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptType;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function serialize;
use function unserialize;

class ApprovalRequestTest extends TestCase
{
    public function test_duplicate_action_ids_are_refused(): void
    {
        // Decisions are keyed by action id: a duplicate could never be decided.
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Duplicate approval action id "call_1"');

        new ApprovalRequest('Approve', [new Action('call_1', 'delete'), new Action('call_1', 'delete')]);
    }

    public function test_actions_keep_their_order(): void
    {
        $request = new ApprovalRequest('Approve', [
            new Action('call_b', 'second'),
            new Action('call_a', 'first'),
            new Action('10', 'numeric'),
        ]);

        $ids = [];
        foreach ($request->getActions() as $action) {
            $ids[] = $action->id;
        }

        $this->assertSame(['call_b', 'call_a', '10'], $ids);
    }

    public function test_it_waits_for_the_approval_event(): void
    {
        $request = new ApprovalRequest('2 tool calls require approval before execution');

        $this->assertSame(ApprovalRequest::EVENT_NAME, $request->getEventName());
        $this->assertSame('approval', $request->getEventName());
        $this->assertSame(InterruptType::WaitForEvent, $request->type());
        $this->assertSame('2 tool calls require approval before execution', $request->getMessage());
        $this->assertSame([], $request->getActions());
    }

    public function test_serialized_shape_is_the_frontend_contract(): void
    {
        $request = (new ApprovalRequest('1 tool call requires approval before execution', [
            new Action(
                id: 'call_1',
                name: 'transfer_money',
                description: '{"amount": 500}',
                decision: ActionDecision::Rejected,
                feedback: 'Too much',
                reason: 'Transfers above $100 require a human sign-off',
                inputs: ['amount' => 500],
            ),
            new Action('call_2', 'ping'),
        ], new DateTimeImmutable('2030-01-01T00:00:00+00:00')))->withId(3);

        $this->assertSame(
            '{"interruptId":3,"type":"wait_for_event","eventName":"approval","expiresAt":"2030-01-01T00:00:00+00:00",'
            . '"message":"1 tool call requires approval before execution","actions":['
            . '{"id":"call_1","name":"transfer_money","description":"{\"amount\": 500}","decision":"rejected",'
            . '"feedback":"Too much","reason":"Transfers above $100 require a human sign-off","inputs":{"amount":500}},'
            . '{"id":"call_2","name":"ping","description":null,"decision":"pending","feedback":null,"reason":null,"inputs":{}}]}',
            json_encode($request)
        );
    }

    public function test_it_survives_persistence_round_trips(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('call_1', 'delete', inputs: ['path' => '/tmp/x'])]))->withId(7);

        $restored = unserialize(serialize($request));

        $this->assertEquals($request, $restored);
        $this->assertSame(7, $restored->getId());
        $this->assertSame(['path' => '/tmp/x'], $restored->getActions()[0]->inputs);
    }

    public function test_action_decision_predicates_are_mutually_exclusive(): void
    {
        foreach (ActionDecision::cases() as $decision) {
            $action = new Action('call_1', 'delete', decision: $decision);

            $this->assertSame($decision === ActionDecision::Pending, $action->isPending());
            $this->assertSame($decision === ActionDecision::Approved, $action->isApproved());
            $this->assertSame($decision === ActionDecision::Rejected, $action->isRejected());
        }
    }

    public function test_a_new_action_is_pending(): void
    {
        $this->assertTrue((new Action('call_1', 'delete'))->isPending());
    }
}
