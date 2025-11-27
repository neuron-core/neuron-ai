<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Traits;

use function fclose;
use function fsockopen;
use function is_resource;

trait CheckOpenPort
{
    private function isPortOpen(string $host, int $port, float $timeout = 0.5): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }
}
