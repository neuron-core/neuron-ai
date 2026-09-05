<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Tests\Workflow\Persistence\Stub\SqlPersistenceFactory;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function array_sum;
use function bin2hex;
use function fclose;
use function fgets;
use function file_exists;
use function fwrite;
use function json_decode;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function str_repeat;
use function stream_get_contents;
use function stream_set_timeout;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

class SqlPersistenceTest extends TestCase
{
    protected ?PDO $pdo = null;
    protected string $table;
    protected string $sqliteFile;

    protected function setUp(): void
    {
        $this->table = 'workflow_test_' . bin2hex(random_bytes(6));
        $file = tempnam(sys_get_temp_dir(), 'workflow_sql_');
        self::assertNotFalse($file);
        $this->sqliteFile = $file;
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$this->table}");
            $this->pdo = null;
        }
        if (file_exists($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
    }

    protected function backend(string $driver, bool $eloquent): DatabasePersistence
    {
        $this->pdo = SqlPersistenceFactory::connect($driver, $this->sqliteFile);
        $keyType = $driver === 'mysql'
            ? 'VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin'
            : 'VARCHAR(510)';
        $valueType = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET ascii' : 'TEXT';
        $quote = $driver === 'mysql' ? '`' : '"';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        $this->pdo->exec("CREATE TABLE {$this->table} (
            {$quote}partition{$quote} {$keyType} NOT NULL,
            {$quote}key{$quote} {$keyType} NOT NULL,
            {$quote}value{$quote} {$valueType} NOT NULL CHECK ({$quote}value{$quote} <> 'Zm9yYmlkZGVu'),
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY ({$quote}partition{$quote}, {$quote}key{$quote})
        ){$engine}");

        return SqlPersistenceFactory::make($this->pdo, $this->table, $eloquent);
    }

    /** @return array<string, array{string, bool}> */
    public static function backendProvider(): array
    {
        $backends = [];
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $backends[$driver . '-pdo'] = [$driver, false];
            $backends[$driver . '-eloquent'] = [$driver, true];
        }

        return $backends;
    }

    /** @dataProvider backendProvider */
    public function test_binary_values_and_distinct_identifiers_round_trip(string $driver, bool $eloquent): void
    {
        $store = $this->backend($driver, $eloquent);
        $value = "\0\xFF\xFE" . str_repeat('payload', 20000);
        $names = ['Order:A', 'order:a', 'order:a ', 'ordine:è', 'ordine:e', "binary\0\xFF", '123', str_repeat('n', 255)];
        foreach ($names as $name) {
            self::assertTrue($store->initializeIfAbsent($name, '__control', $value, [$name => $value]));
        }
        foreach ($names as $name) {
            self::assertSame($value, $store->get($name, $name));
            self::assertTrue($store->writeIfUnchanged($name, '__control', $value, [
                '__control' => 'next', 'Memo:A' => 'upper', 'memo:a' => 'lower',
            ]));
            self::assertSame('upper', $store->get($name, 'Memo:A'));
            self::assertSame('lower', $store->get($name, 'memo:a'));
            self::assertFalse($store->deleteIfUnchanged($name, '__control', $value));
            self::assertTrue($store->deleteIfUnchanged($name, '__control', 'next'));
            self::assertNull($store->get($name, $name));
        }
    }

    /** @dataProvider backendProvider */
    public function test_oversized_record_keys_fail_without_partial_initialization(string $driver, bool $eloquent): void
    {
        $store = $this->backend($driver, $eloquent);
        try {
            $store->initializeIfAbsent('workflow', '__control', 'owner', [str_repeat('x', 256) => 'value']);
            self::fail('Expected the oversized key to fail.');
        } catch (PersistenceException $e) {
            self::assertStringContainsString('255 bytes', $e->getMessage());
        }
        self::assertNull($store->get('workflow', '__control'));
    }

    /** @dataProvider backendProvider */
    public function test_database_error_rolls_back_the_control_and_related_records(string $driver, bool $eloquent): void
    {
        $store = $this->backend($driver, $eloquent);
        try {
            $store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'forbidden']);
            self::fail('Expected the related record to violate the check constraint.');
        } catch (PDOException) {
            self::assertNull($store->get('workflow', '__control'));
            self::assertNull($store->get('workflow', 'step'));
        }
    }

    /** @dataProvider backendProvider */
    public function test_initialization_does_not_ignore_constraint_errors(string $driver, bool $eloquent): void
    {
        $store = $this->backend($driver, $eloquent);
        try {
            $store->initializeIfAbsent('workflow', '__control', 'forbidden');
            self::fail('Expected the initial record to violate the check constraint.');
        } catch (PDOException) {
            self::assertNull($store->get('workflow', '__control'));
        }
    }

    public function test_eloquent_commits_remain_inside_the_callers_transaction(): void
    {
        $store = $this->backend('sqlite', true);
        $connection = Capsule::connection();
        $connection->beginTransaction();
        try {
            self::assertTrue($store->initializeIfAbsent('workflow', '__control', 'owner', ['step' => 'value']));
            self::assertSame(1, $connection->transactionLevel());
            self::assertSame('value', $store->get('workflow', 'step'));
        } finally {
            $connection->rollBack();
        }
        self::assertNull($store->get('workflow', '__control'));
        self::assertNull($store->get('workflow', 'step'));
    }

    public function test_mysql_requires_strict_mode(): void
    {
        $this->backend('mysql', false);
        $mode = $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec("SET SESSION sql_mode = ''");
        try {
            $this->expectException(PersistenceException::class);
            $this->expectExceptionMessage('strict SQL mode');
            new DatabasePersistence($this->pdo, $this->table);
        } finally {
            $this->pdo->exec('SET SESSION sql_mode = ' . $this->pdo->quote($mode));
        }
    }

    /** @return array<string, array{string, bool, string}> */
    public static function raceProvider(): array
    {
        $cases = [];
        foreach (self::backendProvider() as $backend => [$driver, $eloquent]) {
            foreach (['initialize', 'write', 'delete'] as $action) {
                $cases[$backend . '-' . $action] = [$driver, $eloquent, $action];
            }
        }

        return $cases;
    }

    /** @dataProvider raceProvider */
    public function test_competing_workers_commit_only_one_transition(string $driver, bool $eloquent, string $action): void
    {
        $store = $this->backend($driver, $eloquent);
        if ($action !== 'initialize') {
            $store->initializeIfAbsent('race', '__control', 'owner');
        }

        $processes = [];
        $pipes = [];
        try {
            foreach (['first', 'second'] as $index => $marker) {
                $command = [PHP_BINARY, '-r',
                    'require $argv[1]; array_splice($argv, 1, 1); NeuronAI\\Tests\\Workflow\\Persistence\\Stub\\SqlPersistenceWorker::run($argv);',
                    __DIR__ . '/../../../vendor/autoload.php',
                    $driver, $eloquent ? '1' : '0', $this->table,
                    $action === 'delete' && $index === 1 ? 'write' : $action,
                    $marker, $this->sqliteFile,
                ];
                $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $workerPipes);
                self::assertIsResource($process);
                $processes[] = $process;
                $pipes[] = $workerPipes;
                stream_set_timeout($workerPipes[1], 20);
                self::assertSame("ready\n", fgets($workerPipes[1]));
            }
            foreach ($pipes as $workerPipes) {
                fwrite($workerPipes[0], "go\n");
                fclose($workerPipes[0]);
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $output = stream_get_contents($pipes[$index][1]);
                $error = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $exitCode = proc_close($process);
                unset($processes[$index]);
                self::assertSame(0, $exitCode, $error);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertSame(1, array_sum($results));
            if ($action === 'delete' && $results[0]) {
                self::assertNull($store->get('race', '__control'));
                self::assertNull($store->get('race', 'second'));
            } else {
                $winner = $results[0] ? 'first' : 'second';
                $loser = $results[0] ? 'second' : 'first';
                self::assertSame($winner, $store->get('race', '__control'));
                self::assertSame('result', $store->get('race', $winner));
                self::assertNull($store->get('race', $loser));
            }
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
