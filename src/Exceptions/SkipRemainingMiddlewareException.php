<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use Exception;

/**
 * Thrown by a middleware's before() method to terminate the before-middleware chain.
 *
 * Remaining middleware are skipped; node execution proceeds normally.
 * Middleware is responsible for preparing any necessary state (e.g. replacing
 * tool callables with ToolRejectionHandler) before throwing.
 *
 * after() methods are NOT suppressed — they still run for all middleware.
 */
class SkipRemainingMiddlewareException extends Exception
{
    public function __construct(string $message = 'Skipping remaining middleware')
    {
        parent::__construct($message);
    }
}
