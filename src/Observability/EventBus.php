<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

/**
 * Global event bus for observability.
 *
 * IMPORTANT: This class uses static storage. Observers persist for the lifetime
 * of the PHP process. In long-running processes (queue workers, PHP-FPM, Swoole),
 * you MUST call clear() between logical units of work to prevent observer
 * accumulation and stale reference bugs.
 *
 * Example for Laravel queue jobs:
 * ```php
 * public function handle(): void
 * {
 *     EventBus::clear(); // Clear stale observers from previous jobs
 *     $agent->observe(new MyObserver());
 *     // ... rest of job
 * }
 * ```
 */
class EventBus
{
    /**
     * @var ObserverInterface[]
     */
    private static array $observers = [];

    private static bool $initialized = false;

    private static ?InspectorObserver $defaultObserver = null;

    /**
     * Register an observer to receive all events.
     *
     * WARNING: In long-running processes, observers accumulate across requests.
     * Call clear() at the start of each logical unit of work, or use
     * removeObserver() for explicit cleanup.
     */
    public static function observe(ObserverInterface $observer): void
    {
        self::$initialized = true;
        self::$observers[] = $observer;
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
     */
    public static function clear(): void
    {
        self::$observers = [];
        self::$initialized = false;
    }
}
