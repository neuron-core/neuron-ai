<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ApprovalRequestTest extends TestCase
{
    public function testConstructorWithMessage(): void
    {
        $request = new ApprovalRequest('Test message');

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertEmpty($request->getActions());
    }

    public function testConstructorWithActions(): void
    {
        $actions = [
            new Action('action1', 'First Action', 'Description 1'),
            new Action('action2', 'Second Action', 'Description 2'),
        ];

        $request = new ApprovalRequest('Test message', $actions);

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertCount(2, $request->getActions());
        $this->assertEquals('action1', $request->getActions()[0]->id);
        $this->assertEquals('action2', $request->getActions()[1]->id);
    }

    public function testAddAction(): void
    {
        $request = new ApprovalRequest('Test message');
        $action = new Action('action1', 'Test Action');

        $result = $request->addAction($action);

        $this->assertSame($request, $result);
        $this->assertCount(1, $request->getActions());
        $this->assertEquals('action1', $request->getActions()[0]->id);
    }

    public function testGetAction(): void
    {
        $action = new Action('action1', 'Test Action');
        $request = new ApprovalRequest('Test message', [$action]);

        $retrieved = $request->getAction('action1');

        $this->assertSame($action, $retrieved);
    }

    public function testGetActionReturnsNullForNonExistentId(): void
    {
        $request = new ApprovalRequest('Test message');

        $retrieved = $request->getAction('nonexistent');

        $this->assertNull($retrieved);
    }

    public function testSetActions(): void
    {
        $request = new ApprovalRequest('Test message');
        $actions = [
            new Action('action1', 'First Action'),
            new Action('action2', 'Second Action'),
        ];

        $result = $request->setActions($actions);

        $this->assertSame($request, $result);
        $this->assertCount(2, $request->getActions());
    }

    public function testGetPendingActions(): void
    {
        $pendingAction = new Action('action1', 'Pending Action', null, ActionDecision::Pending);
        $approvedAction = new Action('action2', 'Approved Action', null, ActionDecision::Approved);
        $rejectedAction = new Action('action3', 'Rejected Action', null, ActionDecision::Rejected);

        $request = new ApprovalRequest('Test message', [$pendingAction, $approvedAction, $rejectedAction]);

        $pending = $request->getPendingActions();

        $this->assertCount(1, $pending);
        $this->assertArrayHasKey('action1', $pending);
        $this->assertSame($pendingAction, $pending['action1']);
    }

    public function testGetApprovedActions(): void
    {
        $pendingAction = new Action('action1', 'Pending Action', null, ActionDecision::Pending);
        $approvedAction = new Action('action2', 'Approved Action', null, ActionDecision::Approved);

        $request = new ApprovalRequest('Test message', [$pendingAction, $approvedAction]);

        $approved = $request->getApprovedActions();

        $this->assertCount(1, $approved);
        $this->assertArrayHasKey('action2', $approved);
        $this->assertSame($approvedAction, $approved['action2']);
    }

    public function testGetRejectedActions(): void
    {
        $pendingAction = new Action('action1', 'Pending Action', null, ActionDecision::Pending);
        $rejectedAction = new Action('action2', 'Rejected Action', null, ActionDecision::Rejected);

        $request = new ApprovalRequest('Test message', [$pendingAction, $rejectedAction]);

        $rejected = $request->getRejectedActions();

        $this->assertCount(1, $rejected);
        $this->assertArrayHasKey('action2', $rejected);
        $this->assertSame($rejectedAction, $rejected['action2']);
    }

    public function testJsonSerialize(): void
    {
        $actions = [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', 'Description 2', ActionDecision::Approved, 'Looks good'),
        ];

        $request = new ApprovalRequest('Test message', $actions);

        $serialized = $request->jsonSerialize();

        $this->assertArrayHasKey('message', $serialized);
        $this->assertArrayHasKey('actions', $serialized);
        $this->assertEquals('Test message', $serialized['message']);
        $this->assertIsString($serialized['actions']);

        // Verify actions are JSON-encoded
        $decodedActions = json_decode($serialized['actions'], true);
        $this->assertIsArray($decodedActions);
        $this->assertCount(2, $decodedActions);
        $this->assertEquals('action1', $decodedActions[0]['id']);
        $this->assertEquals('action2', $decodedActions[1]['id']);
    }

    public function testJsonEncoding(): void
    {
        $actions = [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', 'Description 2', ActionDecision::Approved, 'Approved!'),
        ];

        $request = new ApprovalRequest('Test message', $actions);

        $json = json_encode($request);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('Test message', $decoded['message']);
        $this->assertIsString($decoded['actions']);
    }

    public function testFromArrayWithActionsAsArray(): void
    {
        $data = [
            'message' => 'Test message',
            'actions' => [
                [
                    'id' => 'action1',
                    'name' => 'First Action',
                    'description' => 'Description 1',
                    'decision' => 'pending',
                    'feedback' => null,
                ],
                [
                    'id' => 'action2',
                    'name' => 'Second Action',
                    'description' => 'Description 2',
                    'decision' => 'approved',
                    'feedback' => 'Looks good',
                ],
            ],
        ];

        $request = ApprovalRequest::fromArray($data);

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertCount(2, $request->getActions());

        $actions = $request->getActions();
        $this->assertEquals('action1', $actions[0]->id);
        $this->assertEquals('First Action', $actions[0]->name);
        $this->assertEquals('Description 1', $actions[0]->description);
        $this->assertEquals(ActionDecision::Pending, $actions[0]->decision);

        $this->assertEquals('action2', $actions[1]->id);
        $this->assertEquals('Second Action', $actions[1]->name);
        $this->assertEquals('Description 2', $actions[1]->description);
        $this->assertEquals(ActionDecision::Approved, $actions[1]->decision);
        $this->assertEquals('Looks good', $actions[1]->feedback);
    }

    public function testFromArrayWithActionsAsJsonString(): void
    {
        $actionsArray = [
            [
                'id' => 'action1',
                'name' => 'First Action',
                'description' => 'Description 1',
                'decision' => 'pending',
                'feedback' => null,
            ],
            [
                'id' => 'action2',
                'name' => 'Second Action',
                'description' => 'Description 2',
                'decision' => 'rejected',
                'feedback' => 'Not allowed',
            ],
        ];

        $data = [
            'message' => 'Test message',
            'actions' => json_encode($actionsArray),
        ];

        $request = ApprovalRequest::fromArray($data);

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertCount(2, $request->getActions());

        $actions = $request->getActions();
        $this->assertEquals('action1', $actions[0]->id);
        $this->assertEquals('action2', $actions[1]->id);
        $this->assertEquals(ActionDecision::Rejected, $actions[1]->decision);
        $this->assertEquals('Not allowed', $actions[1]->feedback);
    }

    public function testFromArrayWithoutActions(): void
    {
        $data = [
            'message' => 'Test message',
        ];

        $request = ApprovalRequest::fromArray($data);

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertEmpty($request->getActions());
    }

    public function testRoundTripJsonSerialization(): void
    {
        $originalActions = [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', 'Description 2', ActionDecision::Approved, 'Great!'),
            new Action('action3', 'Third Action', null, ActionDecision::Rejected, 'Not good'),
        ];

        $originalRequest = new ApprovalRequest('Original message', $originalActions);

        // Serialize to array
        $serialized = $originalRequest->jsonSerialize();

        // Deserialize from array
        $restoredRequest = ApprovalRequest::fromArray($serialized);

        // Verify all data is preserved
        $this->assertEquals($originalRequest->getMessage(), $restoredRequest->getMessage());
        $this->assertCount(3, $restoredRequest->getActions());

        $restoredActions = $restoredRequest->getActions();

        $this->assertEquals('action1', $restoredActions[0]->id);
        $this->assertEquals('First Action', $restoredActions[0]->name);
        $this->assertEquals('Description 1', $restoredActions[0]->description);
        $this->assertEquals(ActionDecision::Pending, $restoredActions[0]->decision);
        $this->assertNull($restoredActions[0]->feedback);

        $this->assertEquals('action2', $restoredActions[1]->id);
        $this->assertEquals('Second Action', $restoredActions[1]->name);
        $this->assertEquals('Description 2', $restoredActions[1]->description);
        $this->assertEquals(ActionDecision::Approved, $restoredActions[1]->decision);
        $this->assertEquals('Great!', $restoredActions[1]->feedback);

        $this->assertEquals('action3', $restoredActions[2]->id);
        $this->assertEquals('Third Action', $restoredActions[2]->name);
        $this->assertNull($restoredActions[2]->description);
        $this->assertEquals(ActionDecision::Rejected, $restoredActions[2]->decision);
        $this->assertEquals('Not good', $restoredActions[2]->feedback);
    }

    public function testCompleteJsonEncodeDecodeRoundTrip(): void
    {
        $originalActions = [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', null, ActionDecision::Edit, 'Please modify'),
        ];

        $originalRequest = new ApprovalRequest('Complete test', $originalActions);

        // Full JSON encode
        $json = json_encode($originalRequest);
        $this->assertIsString($json);

        // Full JSON decode
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        // Reconstruct from decoded data
        $restoredRequest = ApprovalRequest::fromArray($decoded);

        // Verify complete restoration
        $this->assertEquals('Complete test', $restoredRequest->getMessage());
        $this->assertCount(2, $restoredRequest->getActions());

        $restoredActions = $restoredRequest->getActions();
        $this->assertEquals('action1', $restoredActions[0]->id);
        $this->assertEquals(ActionDecision::Pending, $restoredActions[0]->decision);

        $this->assertEquals('action2', $restoredActions[1]->id);
        $this->assertEquals(ActionDecision::Edit, $restoredActions[1]->decision);
        $this->assertEquals('Please modify', $restoredActions[1]->feedback);
    }
}
