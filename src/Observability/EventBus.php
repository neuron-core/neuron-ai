<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

class EventBus
{
    /**
     * @var ObserverInterface[]
     */
    private static array $observers = [];

    private static bool $initialized = false;

    public static function observe(ObserverInterface $observer): void
    {
        self::$observers[] = $observer;
    }

    public static function emit(string $event, object $source, mixed $data = null): void
    {
        if (!self::$initialized) {
            self::$initialized = true;
            self::observe(InspectorObserver::instance());
        }

        foreach (self::$observers as $observer) {
            $observer->onEvent($event, $source, $data);
        }
    }

    public static function clear(): void
    {
        self::$observers = [];
        self::$initialized = false;
    }
}
