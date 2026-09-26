<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;

final class PhpSerializerAllowedClassesTest extends TestCase
{
    protected function setUp(): void
    {
        PlantedGadget::$woken = 0;
    }

    public function test_a_restricted_serializer_never_instantiates_a_class_outside_its_allow_list(): void
    {
        $serializer = new PhpSerializer(allowedClasses: [WorkflowControl::class]);

        $restored = $serializer->unserialize(serialize(new PlantedGadget()));

        $this->assertNotInstanceOf(PlantedGadget::class, $restored);
        $this->assertSame(0, PlantedGadget::$woken);
    }

    public function test_a_restricted_serializer_still_round_trips_allowed_classes(): void
    {
        $serializer = new PhpSerializer(allowedClasses: [WorkflowControl::class, WorkflowStatus::class]);
        $control = new WorkflowControl('run-1', WorkflowStatus::Running);

        $restored = $serializer->unserialize($serializer->serialize($control));

        $this->assertEquals($control, $restored);
    }

    public function test_a_planted_control_record_is_refused_without_waking_the_planted_object(): void
    {
        $persistence = new InMemoryPersistence();
        $persistence->initializeIfAbsent('order:1', '__control', serialize(new PlantedGadget()));
        PlantedGadget::$woken = 0;
        $engine = new WorkflowEngine($persistence, new PhpSerializer(allowedClasses: [WorkflowControl::class]));

        try {
            $engine->inspect('order:1');
            $this->fail('A planted __control record must be refused.');
        } catch (WorkflowException $exception) {
            $this->assertSame("Invalid __control record for workflow ID 'order:1'.", $exception->getMessage());
        }

        $this->assertSame(0, PlantedGadget::$woken);
    }
}

final class PlantedGadget
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}
