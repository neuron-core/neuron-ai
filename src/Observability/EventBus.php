<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

use function array_search;
use function array_values;
use function count;
use function in_array;
use function spl_object_id;

/**
 * Global event bus for observability with optional scope support.
 *
 * IMPORTANT: This class uses static storage. Observers persist for the lifetime
 * of the PHP process. In long-running processes (queue workers, PHP-FPM, Swoole),
 * you MUST manage observer lifecycle to prevent accumulation and stale reference bugs.
 *
 * Two approaches for cleanup:
 *
 * 1. Manual cleanup with clear() - clears ALL observers:
 * ```php
 * public function handle(): void
 * {
 *     EventBus::clear(); // Clear all observers from previous jobs
 *     $agent->observe(new MyObserver());
 *     // ... rest of job
 * }
 * ```
 *
 * 2. Scoped observers (recommended) - auto-cleanup per workflow:
 * ```php
 * // Observers registered via $workflow->observe() are scoped
 * // and automatically cleared when the workflow completes.
 * $workflow->observe(new MyObserver()); // Scoped to this workflow
 * $workflow->init()->run(); // Observer auto-cleared on completion
 * ```
 */
class EventBus
{
    /**
     * @var ObserverInterface[]
     */
    private static array $observers = [];

    /**
     * Maps observer object ID to scope identifier.
     * @var array<int, string>
     */
    private static array $observerScopes = [];

    private static bool $initialized = false;

    private static ?InspectorObserver $defaultObserver = null;

    /**
     * Register an observer to receive all events.
     *
     * @param ObserverInterface $observer The observer to register
     * @param string|null $scope Optional scope ID. If provided, observer can be
     *                           removed later via clearScope(). If null, observer
     *                           persists until explicitly removed or clear() is called.
     *
     * In long-running processes, prefer using scoped observers (via Workflow::observe())
     * which auto-clear on workflow completion, or call clear() manually.
     */
    public static function observe(ObserverInterface $observer, ?string $scope = null): void
    {
        self::$initialized = true;
        self::$observers[] = $observer;

        if ($scope !== null) {
            self::$observerScopes[spl_object_id($observer)] = $scope;
        }
    }

    /**
     * Remove a specific observer from the event bus.
     *
     * Use this for explicit cleanup when an observer's lifecycle ends.
     *
     * @param ObserverInterface $observer The observer instance to remove
     * @return bool True if the observer was found and removed, false otherwise
     */
    public static function removeObserver(ObserverInterface $observer): bool
    {
        $key = array_search($observer, self::$observers, true);
        if ($key !== false) {
            // Also remove from scope tracking
            unset(self::$observerScopes[spl_object_id($observer)]);
            unset(self::$observers[$key]);
            self::$observers = array_values(self::$observers); // Re-index
            return true;
        }
        return false;
    }

    /**
     * Check if an observer is already registered.
     *
     * Useful to prevent duplicate observer registration.
     */
    public static function hasObserver(ObserverInterface $observer): bool
    {
        return in_array($observer, self::$observers, true);
    }

    /**
     * Get the current count of registered observers.
     *
     * Useful for debugging observer accumulation issues.
     */
    public static function getObserverCount(): int
    {
        return count(self::$observers);
    }

    /**
     * Clear all observers registered with a specific scope.
     *
     * Unscoped observers are NOT affected. Use this for automatic cleanup
     * when a workflow/agent completes its work.
     *
     * @param string $scope The scope ID to clear
     * @return int Number of observers removed
     */
    public static function clearScope(string $scope): int
    {
        $removed = 0;
        $keepObservers = [];

        foreach (self::$observers as $observer) {
            $observerId = spl_object_id($observer);
            $observerScope = self::$observerScopes[$observerId] ?? null;

            if ($observerScope === $scope) {
                // This observer belongs to the scope being cleared
                unset(self::$observerScopes[$observerId]);
                $removed++;
            } else {
                // Keep this observer
                $keepObservers[] = $observer;
            }
        }

        self::$observers = $keepObservers;
        return $removed;
    }

    /**
     * Get the scope of an observer, if any.
     *
     * @param ObserverInterface $observer The observer to check
     * @return string|null The scope ID, or null if unscoped
     */
    public static function getObserverScope(ObserverInterface $observer): ?string
    {
        return self::$observerScopes[spl_object_id($observer)] ?? null;
    }

    public static function setDefaultObserver(?InspectorObserver $observer): void
    {
        self::$defaultObserver = $observer;
    }

    /**
     * @throws InspectorException
     */
    public static function emit(string $event, object $source, mixed $data = null): void
    {
        if (!self::$initialized) {
            self::observe(self::$defaultObserver ?? InspectorObserver::instance());
        }

        foreach (self::$observers as $observer) {
            $observer->onEvent($event, $source, $data);
        }
    }

    /**
     * Clear all registered observers and reset initialization state.
     *
     * MUST be called at the start of each logical unit of work in long-running
     * processes (queue workers, PHP-FPM, Swoole) to prevent observer accumulation.
     *
     * Note: This clears ALL observers including scoped and unscoped. For selective
     * cleanup, use clearScope() instead.
     */
    public static function clear(): void
    {
        self::$observers = [];
        self::$observerScopes = [];
        self::$initialized = false;
    }
}
