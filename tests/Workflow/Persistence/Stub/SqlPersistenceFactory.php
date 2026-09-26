<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
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

    /**
     * The documented schema of each driver; the Eloquent variant adds its own
     * primary key. Values equal to base64('forbidden') violate a check
     * constraint, so tests can provoke a database error on demand.
     */
    public static function createTable(PDO $pdo, string $table, bool $eloquent): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $keyType = $driver === 'mysql'
            ? 'VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin'
            : 'VARCHAR(510)';
        $valueType = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET ascii' : 'TEXT';
        $quote = $driver === 'mysql' ? '`' : '"';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        $primaryKey = $eloquent ? match ($driver) {
            'mysql' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,',
            'pgsql' => 'id BIGSERIAL PRIMARY KEY,',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT,',
        } : '';
        $constraint = $eloquent ? 'UNIQUE' : 'PRIMARY KEY';
        $pdo->exec("CREATE TABLE {$table} (
            {$primaryKey}
            {$quote}partition{$quote} {$keyType} NOT NULL,
            {$quote}key{$quote} {$keyType} NOT NULL,
            {$quote}value{$quote} {$valueType} NOT NULL CHECK ({$quote}value{$quote} <> 'Zm9yYmlkZGVu'),
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            {$constraint} ({$quote}partition{$quote}, {$quote}key{$quote})
        ){$engine}");
    }

    public static function make(PDO $pdo, string $table, bool $eloquent): PersistenceInterface
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
