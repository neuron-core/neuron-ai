<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use Closure;
use NeuronAI\Tools\Tool;

/**
 * A tool holding a non-serializable dependency (a Closure), standing in for
 * real-world tools built around a PDO connection or an HTTP client.
 */
class ClosureDependencyTool extends Tool
{
    protected string $name = 'count_users';

    protected ?string $description = 'Count the users in the database';

    public function __construct(protected Closure $query)
    {
    }

    public function __invoke(): string
    {
        return (string) ($this->query)();
    }
}
