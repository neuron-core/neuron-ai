<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Tools implement this interface to enable parameter-aware run tracking.
 *
 * Instead of tracking runs by tool name only, these tools provide a unique key
 * that incorporates their input parameters. This allows the same tool to be
 * called multiple times with different parameters (e.g., reading a file in chunks)
 * while still blocking infinite loops with identical parameters.
 */
interface ParameterizedRunKeyTool
{
    /**
     * Get a unique key for tracking tool runs with these specific parameters.
     *
     * The key should uniquely identify this tool invocation. Tools can choose
     * which parameters to include based on their needs:
     *
     * - Include all parameters for strict duplicate detection
     * - Include only key parameters (e.g., file_path for ReadTool, allowing different offsets)
     *
     * @param array $inputs The input parameters for this tool invocation
     * @return string Unique identifier for this tool call with its current parameters
     */
    public function getRunKey(array $inputs): string;
}
