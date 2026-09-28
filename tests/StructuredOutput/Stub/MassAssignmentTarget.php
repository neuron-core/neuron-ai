<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

/**
 * Public state for the model to fill, next to state it must never reach.
 */
class MassAssignmentTarget
{
    #[NotBlank]
    public static string $registry = '';

    public string $name;

    protected bool $isAdmin = false;

    private string $internalToken = 'server-side';

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function internalToken(): string
    {
        return $this->internalToken;
    }
}
