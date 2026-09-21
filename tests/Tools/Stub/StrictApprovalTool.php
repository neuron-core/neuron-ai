<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

/**
 * Declares its risk through strict comparisons, so its approval policy only
 * holds if it reads the same typed values __invoke() receives.
 */
class StrictApprovalTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'delete_account';

    public int $invocations = 0;

    protected function properties(): array
    {
        return [
            new ToolProperty('permanent', PropertyType::BOOLEAN, 'Delete permanently', true),
            new ToolProperty('account_id', PropertyType::INTEGER, 'The account to delete', true),
        ];
    }

    protected function approvalPolicy(): bool|string
    {
        return $this->inputs['permanent'] === true ? 'Permanent deletion' : false;
    }

    public function __invoke(bool $permanent, int $account_id): string
    {
        $this->invocations++;

        return 'deleted';
    }
}
