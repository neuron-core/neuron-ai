<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use PHPUnit\Framework\TestCase;
use Redis;

use function extension_loaded;
use function getenv;

class RedisPersistenceFactory
{
    public static function connect(): Redis
    {
        if (!extension_loaded('redis')) {
            TestCase::markTestSkipped('The redis PHP extension is unavailable.');
        }

        $host = getenv('WORKFLOW_REDIS_HOST');
        if ($host === false || $host === '') {
            TestCase::markTestSkipped('Set WORKFLOW_REDIS_HOST and optionally WORKFLOW_REDIS_PORT for Redis integration tests.');
        }

        $port = getenv('WORKFLOW_REDIS_PORT');
        $client = new Redis();
        $client->connect($host, $port === false ? 6379 : (int) $port, 5);

        return $client;
    }
}
