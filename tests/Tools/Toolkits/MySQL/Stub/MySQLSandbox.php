<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL\Stub;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function in_array;
use function uniqid;

/**
 * A connection to the integration MySQL (port 3306) or MariaDB (port 3307) whose
 * current database is private and uniquely named: DATABASE() sees only the tables
 * a test creates.
 */
class MySQLSandbox
{
    public readonly string $database;

    protected function __construct(public readonly PDO $pdo)
    {
        $this->database = 'neuron_mysql_toolkit_' . uniqid();
        $this->pdo->exec("CREATE DATABASE `{$this->database}`");
        $this->pdo->exec("USE `{$this->database}`");
    }

    public static function open(int $port): self
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            TestCase::markTestSkipped("PDO driver 'mysql' is unavailable.");
        }

        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname=neuron-ai", 'root', '');
        } catch (PDOException $exception) {
            TestCase::markTestSkipped("No MySQL server on port {$port}: {$exception->getMessage()}");
        }

        return new self($pdo);
    }

    public function drop(): void
    {
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    }
}
