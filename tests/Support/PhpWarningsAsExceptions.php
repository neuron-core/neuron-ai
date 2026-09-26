<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use Closure;
use ErrorException;

use function restore_error_handler;
use function set_error_handler;

/**
 * PHPUnit only reports PHP warnings, while frameworks such as Laravel and Symfony
 * turn them into exceptions that abort the run: a tool must not raise any.
 */
trait PhpWarningsAsExceptions
{
    protected function withWarningsAsExceptions(Closure $call): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
