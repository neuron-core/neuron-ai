<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Agent\Tools\ClosureDependencyTool;
use NeuronAI\Tools\ApprovalState;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class ToolSerializationTest extends TestCase
{
    public function testSerializationDropsSubclassDependenciesAndKeepsCallData(): void
    {
        $tool = new ClosureDependencyTool(fn (): int => 42);
        $tool->setInputs(['limit' => 5])
            ->setCallId('call_1')
            ->setResult('42')
            ->setApprovalReason('Counts rows in production')
            ->setApprovalState(ApprovalState::Approved);

        /** @var ClosureDependencyTool $shell */
        $shell = unserialize(serialize($tool));

        $this->assertSame('count_users', $shell->getName());
        $this->assertSame('Count the users in the database', $shell->getDescription());
        $this->assertSame(['limit' => 5], $shell->getInputs());
        $this->assertSame('call_1', $shell->getCallId());
        $this->assertSame('42', $shell->getResult());
        $this->assertSame(ApprovalState::Approved, $shell->getApprovalState());
        $this->assertSame('Counts rows in production', $shell->getApprovalReason());

        $this->assertTrue($shell->isDehydrated());
        $this->assertFalse($tool->isDehydrated());
    }

    public function testDehydratedFlagSurvivesClone(): void
    {
        $tool = new ClosureDependencyTool(fn (): int => 42);

        /** @var ClosureDependencyTool $shell */
        $shell = unserialize(serialize($tool));

        $this->assertTrue((clone $shell)->isDehydrated());
    }

    public function testRehydrateTransplantsSubclassDependenciesInPlace(): void
    {
        $tool = new ClosureDependencyTool(fn (): string => 'never');
        $tool->setInputs([])->setCallId('call_1');

        /** @var ClosureDependencyTool $shell */
        $shell = unserialize(serialize($tool));
        $shell->rehydrate(new ClosureDependencyTool(fn (): string => '42'));

        $this->assertFalse($shell->isDehydrated());
        $this->assertSame('call_1', $shell->getCallId(), 'Call data must stay untouched');

        $shell->execute();
        $this->assertSame('42', $shell->getResult());
    }

    public function testRehydrateSkipsSilentlyWithoutValidDonor(): void
    {
        $tool = new ClosureDependencyTool(fn (): string => '42');

        // A live tool with the same name but a different class is not a valid donor.
        $differentClass = new class (fn (): string => '42') extends ClosureDependencyTool {
        };

        /** @var ClosureDependencyTool $shell */
        $shell = unserialize(serialize($tool));

        $shell->rehydrate(null);
        $this->assertTrue($shell->isDehydrated());

        $shell->rehydrate($differentClass);
        $this->assertTrue($shell->isDehydrated());
    }
}
