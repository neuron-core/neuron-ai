<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools\ToolExecutor;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorInterface;
use NeuronAI\Agent\Skills\Tools\ToolResult;

use function class_exists;
use function sleep;
use function time;

class QueueToolExecutor implements ToolExecutorInterface
{
    /** @var callable|null Resolver that dispatches and returns a job ID */
    private $dispatcher = null;

    /** @var callable|null Resolver that checks job status and returns result or null */
    private $poller = null;

    /**
     * @param callable|null $dispatcher fn(array $inputs, array $config): string (returns job ID)
     * @param callable|null $poller fn(string $jobId, array $config): mixed (returns result or null if pending)
     */
    public function __construct(?callable $dispatcher = null, ?callable $poller = null)
    {
        $this->dispatcher = $dispatcher;
        $this->poller = $poller;
    }

    public function supports(string $type): bool
    {
        return $type === 'queue';
    }

    public function execute(ToolDefinition $definition, array $inputs): ToolResult
    {
        $config = $definition->execution;

        if ($this->dispatcher === null || $this->poller === null) {
            return new ToolResult(
                exitCode: 1,
                error: 'Queue executor requires dispatcher and poller callables to be configured.',
            );
        }

        $pollInterval = (int) ($config['poll_interval'] ?? 2);
        $timeout = (int) ($config['timeout'] ?? 60);

        try {
            $jobId = ($this->dispatcher)($inputs, $config);

            $start = time();
            while (true) {
                $result = ($this->poller)($jobId, $config);

                if ($result !== null) {
                    return new ToolResult(
                        exitCode: 0,
                        output: is_string($result) ? $result : (string) json_encode($result),
                        metadata: ['job_id' => $jobId],
                    );
                }

                if ((time() - $start) >= $timeout) {
                    return new ToolResult(
                        exitCode: 1,
                        error: "Queue job '{$jobId}' timed out after {$timeout} seconds.",
                        metadata: ['job_id' => $jobId],
                    );
                }

                sleep($pollInterval);
            }
        } catch (\Throwable $e) {
            return new ToolResult(
                exitCode: 1,
                error: $e->getMessage(),
            );
        }
    }
}
