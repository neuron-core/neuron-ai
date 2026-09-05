<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use PDO;
use PHPUnit\Framework\TestCase;

use function getenv;
use function in_array;
use function strtoupper;

class SqlPersistenceFactory
{
    public static function connect(string $driver, string $sqliteFile): PDO
    {
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            TestCase::markTestSkipped("PDO driver '{$driver}' is unavailable.");
        }
        if ($driver === 'sqlite') {
            return new PDO('sqlite:' . $sqliteFile, options: [PDO::ATTR_TIMEOUT => 5]);
        }

        $prefix = 'WORKFLOW_' . strtoupper($driver);
        $dsn = getenv($prefix . '_DSN');
        if ($dsn === false || $dsn === '') {
            TestCase::markTestSkipped("Set {$prefix}_DSN, {$prefix}_USER and {$prefix}_PASSWORD for SQL integration tests.");
        }

        return new PDO($dsn, getenv($prefix . '_USER') ?: null, getenv($prefix . '_PASSWORD') ?: null);
    }

    public static function make(PDO $pdo, string $table, bool $eloquent): DatabasePersistence
    {
        if (!$eloquent) {
            return new DatabasePersistence($pdo, $table);
        }

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->setPdo($pdo);
        $capsule->getConnection()->setReadPdo($pdo);
        SqlWorkflowStoreModel::$storeTable = $table;

        return new EloquentPersistence(SqlWorkflowStoreModel::class);
    }
}
