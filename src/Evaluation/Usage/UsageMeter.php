<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Usage;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\Messages\Usage;
use WeakMap;

use function is_int;

/**
 * Counts tokens, one record per provider and model, for one step of one dataset item.
 *
 * An agent is counted through its own listener, so an agent nobody passed to
 * watch() is not counted, and neither is a call whose provider reports no usage.
 * The listener writes to the meter of the step running at that moment, which is
 * why usage survives a step that throws or returns something else. That one
 * static is safe because a process runs one item at a time: concurrency forks.
 */
class UsageMeter
{
    protected static ?self $active = null;

    /** @var array<ModelUsage> */
    protected array $usage = [];

    /** @var WeakMap<InferenceStop, true> An agent watched twice delivers each event twice. */
    protected WeakMap $counted;

    public function __construct()
    {
        $this->counted = new WeakMap();
    }

    /**
     * Copies made afterwards with for() are counted too, each under its own
     * provider and model. Outside a step the listener does nothing, and an agent
     * watched twice is counted once.
     *
     * @template T of AgentInterface
     * @param T $agent
     * @return T
     */
    public static function watch(AgentInterface $agent): AgentInterface
    {
        $agent->subscribe(InferenceStop::class, static function (InferenceStop $event): void {
            self::$active?->record($event);
        });

        return $agent;
    }

    /**
     * Run one step, counting into this meter what the watched agents consume.
     *
     * @template T
     * @param callable(): T $step
     * @return T
     */
    public function during(callable $step): mixed
    {
        $previous = self::$active;
        self::$active = $this;

        try {
            return $step();
        } finally {
            self::$active = $previous;
        }
    }

    /**
     * @return array<ModelUsage>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    protected function record(InferenceStop $event): void
    {
        if (isset($this->counted[$event])) {
            return;
        }

        $this->counted[$event] = true;

        $message = $event->response->message();
        $usage = $message->getUsage();

        // A stream that ends with no usage frame reports zeros, which is no measure
        if (!$usage instanceof Usage || $usage->getTotal() === 0) {
            return;
        }

        $cacheWrites = $message->getMetadata('cacheWriteTokens');
        $toolInputs = $message->getMetadata('toolInputTokens');

        $this->usage = ModelUsage::merge([...$this->usage, new ModelUsage(
            $event->provider::class,
            $event->provider->getModel(),
            1,
            $usage->inputTokens + (is_int($toolInputs) ? $toolInputs : 0),
            $usage->outputTokens,
            $usage->cachedInputTokens,
            is_int($cacheWrites) ? $cacheWrites : 0,
            $usage->reasoningTokens,
        )]);
    }
}
