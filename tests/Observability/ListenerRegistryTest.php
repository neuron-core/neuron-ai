<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use NeuronAI\Tests\Observability\Stub\StoppableTestEvent;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use stdClass;

use function iterator_to_array;

class ListenerRegistryTest extends TestCase
{
    public function test_an_event_without_listeners_gets_none(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen(CustomTestEvent::class, static function (): void {
        });

        $this->assertSame([], $this->listenersFor($registry, new StoppableTestEvent()));
    }

    public function test_listeners_of_one_class_are_returned_in_registration_order(): void
    {
        $first = static function (): void {
        };
        $second = static function (): void {
        };
        $registry = new ListenerRegistry();
        $registry->listen(CustomTestEvent::class, $first);
        $registry->listen(CustomTestEvent::class, $second);

        $this->assertSame([$first, $second], $this->listenersFor($registry, new CustomTestEvent('value')));
    }

    public function test_listeners_match_parent_classes_and_interfaces(): void
    {
        $everything = static function (): void {
        };
        $stoppable = static function (): void {
        };
        $custom = static function (): void {
        };
        $registry = new ListenerRegistry();
        $registry->listen(ObservabilityEvent::class, $everything);
        $registry->listen(StoppableEventInterface::class, $stoppable);
        $registry->listen(CustomTestEvent::class, $custom);

        $this->assertSame([$everything, $stoppable], $this->listenersFor($registry, new StoppableTestEvent()));
        $this->assertSame([$everything, $custom], $this->listenersFor($registry, new CustomTestEvent('value')));
    }

    public function test_foreign_objects_do_not_reach_observability_listeners(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen(ObservabilityEvent::class, static function (): void {
        });

        $this->assertSame([], $this->listenersFor($registry, new stdClass()));
    }

    /**
     * @return callable[]
     */
    protected function listenersFor(ListenerRegistry $registry, object $event): array
    {
        return iterator_to_array($registry->getListenersForEvent($event), false);
    }
}
