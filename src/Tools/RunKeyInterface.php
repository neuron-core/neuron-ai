<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Tools implement this interface to provide a custom run tracking key.
 *
 * Instead of tracking runs by tool name only, tools implementing this interface
 * define their own key strategy. This allows the same tool to be called multiple
 * times with different parameters while still blocking infinite loops with
 * identical tracking keys.
 *
 * @see TrackByInputs Trait that provides input-based key generation
 */
interface RunKeyInterface
{
    /**
     * Get a unique key for tracking tool runs.
     *
     * Tools can return any string — the tool name itself, a hash of inputs,
     * a combination of specific parameter values, or any custom strategy.
     *
     * @return string Unique identifier for this tool call
     */
    public function getRunKey(): string;
}
