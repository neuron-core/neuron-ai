<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function stripos;

class SchemaToolDiscardedLookupTest extends TestCase
{
    /**
     * @var string[]
     */
    protected array $prepared = [];

    public static function schemaTools(): iterable
    {
        yield 'mysql' => [MySQLSchemaTool::class];
        yield 'pgsql' => [PGSQLSchemaTool::class];
    }

    /**
     * @param class-string<MySQLSchemaTool|PGSQLSchemaTool> $toolClass
     */
    #[DataProvider('schemaTools')]
    public function test_every_lookup_contributes_to_the_rendered_schema(string $toolClass): void
    {
        (new $toolClass($this->recordingPdo()))();

        foreach ($this->prepared as $sql) {
            $this->assertFalse(
                stripos($sql, 'constraint_type IN') !== false,
                "Schema tool ran a constraints lookup that formatForLLM never renders:\n{$sql}"
            );
        }
    }

    protected function recordingPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $this->prepared[] = $sql;
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn([]);
            return $statement;
        });

        return $pdo;
    }
}
