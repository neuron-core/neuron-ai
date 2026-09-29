<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use Closure;
use ErrorException;

use function error_reporting;
use function restore_error_handler;
use function set_error_handler;

use const E_ALL;

/**
 * PHPUnit only reports PHP warnings, while frameworks such as Laravel and Symfony
 * turn them into exceptions that abort the run: a tool must not raise any. Like
 * those frameworks, a warning silenced with `@` is left alone.
 */
trait PhpWarningsAsExceptions
{
    protected function withWarningsAsExceptions(Closure $call): mixed
    {
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }
}
