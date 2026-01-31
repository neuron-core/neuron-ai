<?php

/**
 * Tests for EventBus observer lifecycle management.
 *
 * These tests verify that observers can be added, removed, counted, and cleared,
 * which is critical for preventing observer accumulation in long-running processes.
 */

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\ObserverInterface;
use PHPUnit\Framework\TestCase;

class EventBusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear(); // Ensure clean state
    }

    protected function tearDown(): void
    {
        EventBus::clear(); // Clean up after test
        parent::tearDown();
    }

    public function test_observe_adds_observer(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer);

        $this->assertEquals(1, EventBus::getObserverCount());
        $this->assertTrue(EventBus::hasObserver($observer));
    }

    public function test_observe_allows_multiple_observers(): void
    {
        $observer1 = $this->createMock(ObserverInterface::class);
        $observer2 = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer1);
        EventBus::observe($observer2);

        $this->assertEquals(2, EventBus::getObserverCount());
    }

    public function test_remove_observer_removes_specific_observer(): void
    {
        $observer1 = $this->createMock(ObserverInterface::class);
        $observer2 = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer1);
        EventBus::observe($observer2);

        $result = EventBus::removeObserver($observer1);

        $this->assertTrue($result);
        $this->assertEquals(1, EventBus::getObserverCount());
        $this->assertFalse(EventBus::hasObserver($observer1));
        $this->assertTrue(EventBus::hasObserver($observer2));
    }

    public function test_remove_observer_returns_false_for_unregistered_observer(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        $result = EventBus::removeObserver($observer);

        $this->assertFalse($result);
    }

    public function test_clear_removes_all_observers(): void
    {
        $observer1 = $this->createMock(ObserverInterface::class);
        $observer2 = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer1);
        EventBus::observe($observer2);

        EventBus::clear();

        $this->assertEquals(0, EventBus::getObserverCount());
        $this->assertFalse(EventBus::hasObserver($observer1));
        $this->assertFalse(EventBus::hasObserver($observer2));
    }

    public function test_emit_notifies_all_observers(): void
    {
        $observer1 = $this->createMock(ObserverInterface::class);
        $observer2 = $this->createMock(ObserverInterface::class);

        $observer1->expects($this->once())
            ->method('onEvent')
            ->with('test-event', $this->anything(), ['key' => 'value']);

        $observer2->expects($this->once())
            ->method('onEvent')
            ->with('test-event', $this->anything(), ['key' => 'value']);

        EventBus::observe($observer1);
        EventBus::observe($observer2);

        EventBus::emit('test-event', $this, ['key' => 'value']);
    }

    public function test_has_observer_returns_false_for_unregistered_observer(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        $this->assertFalse(EventBus::hasObserver($observer));
    }

    public function test_get_observer_count_returns_zero_initially(): void
    {
        $this->assertEquals(0, EventBus::getObserverCount());
    }

    public function test_remove_observer_reindexes_array(): void
    {
        $observer1 = $this->createMock(ObserverInterface::class);
        $observer2 = $this->createMock(ObserverInterface::class);
        $observer3 = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer1);
        EventBus::observe($observer2);
        EventBus::observe($observer3);

        // Remove middle observer
        EventBus::removeObserver($observer2);

        // Should still have 2 observers
        $this->assertEquals(2, EventBus::getObserverCount());

        // Both remaining should still work
        $observer1->expects($this->once())->method('onEvent');
        $observer3->expects($this->once())->method('onEvent');

        EventBus::emit('test-event', $this);
    }

    /**
     * Demonstrates the observer accumulation bug fix.
     *
     * This test simulates the queue worker scenario where multiple jobs
     * run in the same process, adding observers without clearing.
     */
    public function test_observer_accumulation_scenario(): void
    {
        // Simulate Job 1
        $observer1 = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer1);
        $this->assertEquals(1, EventBus::getObserverCount());

        // Job 1 completes but no clear() - BUG SCENARIO
        // Simulate Job 2 without clear()
        $observer2 = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer2);

        // BUG: Now there are 2 observers when there should only be 1
        $this->assertEquals(2, EventBus::getObserverCount());

        // FIX: Call clear() at start of each job
        EventBus::clear();
        $observer3 = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer3);

        // Now only 1 observer as expected
        $this->assertEquals(1, EventBus::getObserverCount());
    }

    /**
     * Test that removeObserver can be used for explicit cleanup.
     */
    public function test_explicit_cleanup_with_remove_observer(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        // Simulating: observer = new MyObserver(); agent->observe(observer);
        EventBus::observe($observer);
        $this->assertEquals(1, EventBus::getObserverCount());

        // Simulating: finally { EventBus::removeObserver(observer); }
        $removed = EventBus::removeObserver($observer);

        $this->assertTrue($removed);
        $this->assertEquals(0, EventBus::getObserverCount());
    }

    // =========================================================================
    // Scoped Observer Tests
    // =========================================================================

    public function test_observe_with_scope_tracks_scope(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer, 'workflow_123');

        $this->assertEquals('workflow_123', EventBus::getObserverScope($observer));
    }

    public function test_observe_without_scope_has_null_scope(): void
    {
        $observer = $this->createMock(ObserverInterface::class);

        EventBus::observe($observer);

        $this->assertNull(EventBus::getObserverScope($observer));
    }

    public function test_clear_scope_removes_only_scoped_observers(): void
    {
        $scopedObserver1 = $this->createMock(ObserverInterface::class);
        $scopedObserver2 = $this->createMock(ObserverInterface::class);
        $unscopedObserver = $this->createMock(ObserverInterface::class);
        $differentScopeObserver = $this->createMock(ObserverInterface::class);

        EventBus::observe($scopedObserver1, 'workflow_A');
        EventBus::observe($scopedObserver2, 'workflow_A');
        EventBus::observe($unscopedObserver);  // No scope
        EventBus::observe($differentScopeObserver, 'workflow_B');

        $this->assertEquals(4, EventBus::getObserverCount());

        // Clear only workflow_A scope
        $removed = EventBus::clearScope('workflow_A');

        $this->assertEquals(2, $removed);
        $this->assertEquals(2, EventBus::getObserverCount());
        $this->assertFalse(EventBus::hasObserver($scopedObserver1));
        $this->assertFalse(EventBus::hasObserver($scopedObserver2));
        $this->assertTrue(EventBus::hasObserver($unscopedObserver));
        $this->assertTrue(EventBus::hasObserver($differentScopeObserver));
    }

    public function test_clear_scope_returns_zero_for_unknown_scope(): void
    {
        $observer = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer, 'workflow_A');

        $removed = EventBus::clearScope('workflow_UNKNOWN');

        $this->assertEquals(0, $removed);
        $this->assertEquals(1, EventBus::getObserverCount());
    }

    public function test_remove_observer_also_removes_scope_tracking(): void
    {
        $observer = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer, 'workflow_A');

        $this->assertEquals('workflow_A', EventBus::getObserverScope($observer));

        EventBus::removeObserver($observer);

        // Scope tracking should also be cleaned up
        $this->assertNull(EventBus::getObserverScope($observer));
    }

    public function test_clear_also_clears_scope_tracking(): void
    {
        $observer = $this->createMock(ObserverInterface::class);
        EventBus::observe($observer, 'workflow_A');

        $this->assertEquals('workflow_A', EventBus::getObserverScope($observer));

        EventBus::clear();

        $this->assertEquals(0, EventBus::getObserverCount());
        // Internal scope tracking should also be cleared
        $this->assertNull(EventBus::getObserverScope($observer));
    }

    /**
     * Integration test: Simulates workflow completion clearing only its observers.
     *
     * This demonstrates the primary use case: multiple concurrent workflows
     * each cleaning up only their own observers without affecting others.
     */
    public function test_workflow_completion_clears_only_its_observers(): void
    {
        // Simulate Workflow A
        $scopeA = 'workflow_A';
        $observerA = $this->createMock(ObserverInterface::class);
        EventBus::observe($observerA, $scopeA);

        // Simulate Workflow B
        $scopeB = 'workflow_B';
        $observerB = $this->createMock(ObserverInterface::class);
        EventBus::observe($observerB, $scopeB);

        // Global observer (registered directly, not via workflow)
        $globalObserver = $this->createMock(ObserverInterface::class);
        EventBus::observe($globalObserver);

        $this->assertEquals(3, EventBus::getObserverCount());

        // Workflow A completes
        EventBus::clearScope($scopeA);

        // Only A's observer should be removed
        $this->assertEquals(2, EventBus::getObserverCount());
        $this->assertFalse(EventBus::hasObserver($observerA));
        $this->assertTrue(EventBus::hasObserver($observerB));
        $this->assertTrue(EventBus::hasObserver($globalObserver));

        // Workflow B completes
        EventBus::clearScope($scopeB);

        // Only global observer remains
        $this->assertEquals(1, EventBus::getObserverCount());
        $this->assertTrue(EventBus::hasObserver($globalObserver));
    }

    /**
     * Test that scoped observers still receive events normally.
     */
    public function test_scoped_observers_receive_events(): void
    {
        $scopedObserver = $this->createMock(ObserverInterface::class);
        $unscopedObserver = $this->createMock(ObserverInterface::class);

        $scopedObserver->expects($this->once())
            ->method('onEvent')
            ->with('test-event', $this->anything(), ['data' => 'value']);

        $unscopedObserver->expects($this->once())
            ->method('onEvent')
            ->with('test-event', $this->anything(), ['data' => 'value']);

        EventBus::observe($scopedObserver, 'workflow_123');
        EventBus::observe($unscopedObserver);

        EventBus::emit('test-event', $this, ['data' => 'value']);
    }
}
