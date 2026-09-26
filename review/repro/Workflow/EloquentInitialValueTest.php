<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Tests\Workflow\Persistence\Stub\WorkflowStoreModel;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

class EloquentInitialValueTest extends TestCase
{
    protected const SCHEMA = 'CREATE TABLE workflow_store (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        "partition" VARCHAR(510) NOT NULL,
        "key" VARCHAR(510) NOT NULL,
        "value" TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE ("partition", "key")
    )';

    public function test_the_initial_value_wins_over_a_related_record_with_the_condition_key(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(self::SCHEMA);
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->statement(self::SCHEMA);

        $backends = [
            'in-memory' => new InMemoryPersistence(),
            'database' => new DatabasePersistence($pdo),
            'eloquent' => new EloquentPersistence(WorkflowStoreModel::class),
        ];
        foreach ($backends as $name => $store) {
            $this->assertInitialValueWins($store, $name);
        }
    }

    protected function assertInitialValueWins(PersistenceInterface $store, string $name): void
    {
        $this->assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner', ['__control' => 'related', 'step' => 'result']), $name);
        $this->assertSame('owner', $store->get('workflow', '__control'), $name);
        $this->assertSame('result', $store->get('workflow', 'step'), $name);
    }
}
