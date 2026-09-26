<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * A private temporary directory per test, removed with everything inside it,
 * symlinks included, without ever following them out of the sandbox.
 */
trait FileSystemSandbox
{
    protected function createSandbox(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '_' . uniqid('', true);
        mkdir($path, 0o755, true);

        return (string) realpath($path);
    }

    protected function removeSandbox(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeSandbox($path . '/' . $item);
            }
        }

        rmdir($path);
    }

    protected function symlinkOrSkip(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('Symlinks cannot be created on this platform.');
        }
    }
}
