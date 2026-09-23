<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Tests\Workflow\Persistence\Stub\WorkflowStoreModel;
use NeuronAI\Tests\Workflow\Persistence\Stub\ScopedWorkflowStoreModel;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use PDO;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function bin2hex;
use function explode;
use function hex2bin;

class EloquentPersistenceTest extends TestCase
{
    protected Capsule $capsule;
    protected EloquentPersistence $store;
    protected ?Dispatcher $previousDispatcher;

    protected function setUp(): void
    {
        $this->previousDispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $this->capsule->getConnection()->setPdo($this->database());
        $this->store = new EloquentPersistence(WorkflowStoreModel::class);
    }

    protected function tearDown(): void
    {
        Model::unsetEventDispatcher();
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        }
    }

    protected function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE workflow_store (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            "partition" VARCHAR(510) NOT NULL,
            "key" VARCHAR(510) NOT NULL,
            "value" TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE ("partition", "key")
        )');

        return $pdo;
    }

    public function test_construction_does_not_resolve_a_database_connection(): void
    {
        $resolver = Model::getConnectionResolver();
        Model::unsetConnectionResolver();
        try {
            self::assertInstanceOf(EloquentPersistence::class, new EloquentPersistence(WorkflowStoreModel::class));
        } finally {
            Model::setConnectionResolver($resolver);
        }
    }

    public function test_reads_and_transactions_follow_the_replacement_connection(): void
    {
        $this->store->initializeIfAbsent('workflow', 'control', 'old');
        $connection = $this->capsule->getConnection();
        $oldPdo = $connection->getPdo();
        $connection->setPdo($this->database());
        // A stale read connection must not supply the condition or status.
        $connection->setReadPdo($oldPdo);
        self::assertNull($this->store->get('workflow', 'control'));
        $connection->beginTransaction();
        try {
            self::assertTrue($this->store->initializeIfAbsent('workflow', 'control', 'new'));
            self::assertTrue($this->store->writeIfUnchanged('workflow', 'control', 'new', ['control' => 'updated']));
            self::assertSame('updated', $this->store->get('workflow', 'control'));
        } finally {
            $connection->rollBack();
        }
        self::assertNull($this->store->get('workflow', 'control'));
        self::assertSame(base64_encode('old'), $oldPdo->query('SELECT value FROM workflow_store')->fetchColumn());
    }

    public function test_model_events_are_emitted_for_each_created_updated_and_deleted_record(): void
    {
        $events = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (string $event, Model $record) use (&$events): array {
            $events[] = [explode(':', $event)[0], hex2bin($record->getAttribute('key'))];
            return [];
        });
        Model::setEventDispatcher($dispatcher);

        self::assertTrue($this->store->initializeIfAbsent('workflow', 'control', 'owner', ['step' => 'first']));
        self::assertTrue($this->store->writeIfUnchanged('workflow', 'control', 'owner', ['control' => 'next', 'step' => 'second']));
        self::assertTrue($this->store->deleteIfUnchanged('workflow', 'control', 'next'));

        foreach (['control', 'step'] as $key) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                self::assertContains(['eloquent.' . $event, $key], $events);
            }
        }
    }

    public function test_model_scopes_accessors_timestamps_and_primary_key_are_used(): void
    {
        $this->capsule->getConnection()->statement('CREATE TABLE scoped_workflow_store (
            record_id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant TEXT NOT NULL,
            "partition" VARCHAR(510) NOT NULL,
            "key" VARCHAR(510) NOT NULL,
            "value" TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            UNIQUE ("partition", "key")
        )');
        ScopedWorkflowStoreModel::query()->withoutGlobalScopes()->create([
            'tenant' => 'other',
            'partition' => bin2hex('workflow'),
            'key' => bin2hex('hidden'),
            'value' => base64_encode('untouched'),
        ]);
        $store = new EloquentPersistence(ScopedWorkflowStoreModel::class);
        self::assertNull($store->get('workflow', 'hidden'));
        self::assertTrue($store->initializeIfAbsent('workflow', 'control', 'owner'));
        self::assertSame('owner', $store->get('workflow', 'control'));
        self::assertTrue($store->writeIfUnchanged('workflow', 'control', 'owner', ['control' => 'next']));
        self::assertSame('next', $store->get('workflow', 'control'));
        $record = ScopedWorkflowStoreModel::query()->firstOrFail();
        self::assertSame('wrapped:' . base64_encode('next'), $record->getRawOriginal('value'));
        self::assertNotNull($record->getAttribute('created_at'));
        self::assertNotNull($record->getAttribute('updated_at'));
        self::assertTrue($store->deleteIfUnchanged('workflow', 'control', 'next'));
        self::assertSame(0, ScopedWorkflowStoreModel::query()->count());
        self::assertSame(1, ScopedWorkflowStoreModel::query()->withoutGlobalScopes()->count());
    }

    /**
     * @dataProvider cancelledMutationProvider
     * @param 'initialize'|'write'|'delete' $action
     */
    public function test_cancelled_model_mutations_roll_back_the_entire_operation(string $action, string $event, string $cancelledKey): void
    {
        if ($action !== 'initialize') {
            $this->store->initializeIfAbsent('workflow', 'control', 'owner', ['step' => 'first']);
        }
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('until')->willReturnCallback(
            fn (string $name, Model $record): ?bool => $name === 'eloquent.' . $event . ': ' . WorkflowStoreModel::class
                && $record->getAttribute('key') === bin2hex($cancelledKey) ? false : null,
        );
        Model::setEventDispatcher($dispatcher);

        try {
            match ($action) {
                'initialize' => $this->store->initializeIfAbsent('workflow', 'control', 'owner', ['step' => 'first']),
                'write' => $this->store->writeIfUnchanged('workflow', 'control', 'owner', ['control' => 'next', 'step' => 'second']),
                'delete' => $this->store->deleteIfUnchanged('workflow', 'control', 'owner'),
            };
            self::fail('A cancelled model mutation must fail persistence.');
        } catch (PersistenceException) {
            self::assertSame($action === 'initialize' ? null : 'owner', $this->store->get('workflow', 'control'));
            self::assertSame($action === 'initialize' ? null : 'first', $this->store->get('workflow', 'step'));
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function cancelledMutationProvider(): array
    {
        return [
            'create condition' => ['initialize', 'creating', 'control'],
            'create related' => ['initialize', 'creating', 'step'],
            'update' => ['write', 'updating', 'step'],
            'delete' => ['delete', 'deleting', 'step'],
        ];
    }
}
