<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use NeuronAI\Workflow\Persistence\RedisPersistence;
use Throwable;

use function fgets;
use function fwrite;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const STDERR;
use const STDIN;
use const STDOUT;

class RedisPersistenceWorker
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): void
    {
        [, $prefix, $action, $marker] = $arguments;
        try {
            $store = new RedisPersistence(RedisPersistenceFactory::connect(), $prefix);
            fwrite(STDOUT, "ready\n");
            fgets(STDIN);
            $result = match ($action) {
                'initialize' => $store->initializeIfAbsent('race', '__control', $marker, [$marker => 'result']),
                'delete' => $store->deleteIfUnchanged('race', '__control', 'owner'),
                default => $store->writeIfUnchanged('race', '__control', 'owner', [
                    '__control' => $marker,
                    $marker => 'result',
                ]),
            };
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            fwrite(STDERR, $e::class . ': ' . $e->getMessage());
            exit(1);
        }
    }
}
