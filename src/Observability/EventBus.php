<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

class EventBus
{
    /**
     * Global observers (backward compatibility).
     *
     * @var ObserverInterface[]
     */
    private static array $observers = [];

    /**
     * Scoped observers by workflow ID.
     *
     * @var array<string, ObserverInterface[]>
     */
    private static array $scopedObservers = [];

    /**
     * Tracks initialization per scope.
     *
     * @var array<string, bool>
     */
    private static array $initialized = [];

    private static ?InspectorObserver $defaultObserver = null;

    /**
     * Register an observer, optionally scoped to a workflow.
     *
     * @param ObserverInterface $observer The observer to register
     * @param string|null $workflowId Optional workflow ID for scoping
     */
    public static function observe(ObserverInterface $observer, ?string $workflowId = null): void
    {
        if ($workflowId !== null) {
            self::$initialized[$workflowId] = true;
            self::$scopedObservers[$workflowId][] = $observer;
        } else {
            self::$initialized['__global__'] = true;
            self::$observers[] = $observer;
        }
    }

    public static function setDefaultObserver(?InspectorObserver $observer): void
    {
        self::$defaultObserver = $observer;
    }

    /**
     * Emit an event to observers.
     *
     * When workflowId is provided, emits only to observers registered for that workflow.
     * When workflowId is null, emits to global observers (backward compatibility).
     *
     * @throws InspectorException
     */
    public static function emit(string $event, object $source, mixed $data = null, ?string $workflowId = null): void
    {
        $scope = $workflowId ?? '__global__';

        if (!isset(self::$initialized[$scope]) || !self::$initialized[$scope]) {
            self::observe(self::$defaultObserver ?? InspectorObserver::instance(), $workflowId);
        }

        if ($workflowId !== null) {
            // Emit to scoped observers only
            foreach (self::$scopedObservers[$workflowId] ?? [] as $observer) {
                $observer->onEvent($event, $source, $data);
            }
        } else {
            // Emit to global observers (backward compatibility)
            foreach (self::$observers as $observer) {
                $observer->onEvent($event, $source, $data);
            }
        }
    }

    /**
     * Clear observers, optionally for a specific workflow only.
     *
     * @param string|null $workflowId If provided, only clears observers for that workflow
     */
    public static function clear(?string $workflowId = null): void
    {
        if ($workflowId !== null) {
            unset(self::$scopedObservers[$workflowId]);
            unset(self::$initialized[$workflowId]);
        } else {
            self::$observers = [];
            self::$scopedObservers = [];
            self::$initialized = [];
        }
    }
}
