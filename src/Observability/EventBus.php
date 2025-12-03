<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;

class EventBus
{
    /**
     * @var ObserverInterface[]
     */
    private static array $observers = [];

    private static bool $initialized = false;

    private static ?InspectorObserver $defaultObserver = null;

    public static function observe(ObserverInterface $observer): void
    {
        self::$observers[] = $observer;
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
            self::$initialized = true;
            $observer = self::$defaultObserver ?? InspectorObserver::instance();
            self::observe($observer);
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
