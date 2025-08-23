<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use SplObserver;

trait Observable
{
    /**
     * @var array<string, SplObserver[]>
     */
    private array $observers = [];


    private function initEventGroup(string $event = '*'): void
    {
        /*
         * If developers attach an observer, the agent monitoring will not be attached by default.
         */
        if (!isset($this->observers['*']) && !empty($_ENV['INSPECTOR_INGESTION_KEY'])) {
            $this->observers['*'] = [
                AgentMonitoring::instance(),
            ];
        }

        if (!isset($this->observers[$event])) {
            $this->observers[$event] = [];
        }
    }

    /**
     * @return array<SplObserver>
     */
    private function getEventObservers(string $event = "*"): array
    {
        $this->initEventGroup($event);
        $group = $this->observers[$event];
        $all = $this->observers["*"] ?? [];

        return \array_merge($group, $all);
    }

    public function observe(SplObserver $observer, string $event = "*"): self
    {
        $this->attach($observer, $event);
        return $this;
    }

    public function attach(SplObserver $observer, string $event = "*"): void
    {
        $this->initEventGroup($event);
        $this->observers[$event][] = $observer;
    }

    public function detach(SplObserver $observer, string $event = "*"): void
    {
        foreach ($this->getEventObservers($event) as $key => $s) {
            if ($s === $observer) {
                unset($this->observers[$event][$key]);
            }
        }
    }

    public function notify(string $event = "*", mixed $data = null): void
    {
        // Broadcasting the '$event' event";
        foreach ($this->getEventObservers($event) as $observer) {
            $observer->update($this, $event, $data);
        }
    }
}
