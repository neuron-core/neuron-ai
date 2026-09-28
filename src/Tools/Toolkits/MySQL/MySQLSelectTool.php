<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PDO;
use PDOException;
use ReflectionException;

use function implode;
use function in_array;
use function preg_match;
use function str_starts_with;
use function stripos;
use function strtoupper;

/**
 * The database enforces read-only access: each query runs alone in a read-only
 * transaction that is always rolled back. The text rules only refuse what that
 * transaction lets through, and each refuses more than MySQL would ever run.
 *
 * @method static static make(PDO $pdo)
 */
class MySQLSelectTool extends Tool
{
    protected const READ_ONLY_VIOLATION = '25006';

    protected array $allowedStatements = ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'EXPLAIN'];

    /**
     * How a SELECT reads and writes the server's files, which a read-only transaction allows.
     */
    protected array $fileAccessKeywords = ['OUTFILE', 'DUMPFILE', 'LOAD_FILE'];

    protected string $name = 'mysql_select_query';

    protected ?string $description = 'Use this tool only to run SELECT query against the MySQL database to gather information.

IMPORTANT: When table or column names are MySQL reserved keywords (e.g., character, order, group,
index, key, value, date, time, etc.), you MUST wrap them in backticks (`) to avoid syntax errors.

Examples of correct usage:
- SELECT id, name FROM `character` (not FROM character)
- SELECT * FROM `order` WHERE status = :status
- SELECT user_id, `key`, value FROM settings WHERE `key` LIKE :pattern
- SELECT COUNT(*) FROM `group` WHERE `group`.id IN (1, 2, 3)

Always use backticks around identifiers that are reserved keywords.';

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * @throws ReflectionException
     * @throws ArrayPropertyException
     * @throws ToolException
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The SELECT query. Use backticks (`) around table/column names that are MySQL reserved keywords (e.g., `character`, `order`, `group`, `index`, `key`, `value`, `date`, `time`). Use named placeholders (:parameter_name) for all dynamic values. Examples: "SELECT id, name FROM `character` WHERE type = :type", "SELECT * FROM `order` WHERE status = :status"',
                required: true
            ),
            new ArrayProperty(
                name: 'parameters',
                description: 'Key-value pairs for parameter binding where keys match the named placeholders in the query (without the colon). Example: {"name": "John Doe", "email": "%john%", "id": 123}. Ignore if no parameters are needed.',
                required: false,
                items: new ObjectProperty(
                    name: 'parameter',
                    properties: [
                        new ToolProperty('name', PropertyType::STRING, 'Parameter name', true),
                        new ToolProperty('value', PropertyType::STRING, 'Parameter value', true),
                    ]
                )
            ),
        ];
    }

    /**
     * @param array<array{name: string, value: string}>|null $parameters
     * @throws ToolException
     */
    public function __invoke(string $query, ?array $parameters = []): array|ToolOutput
    {
        $refusal = $this->refusal($query);
        if ($refusal !== null) {
            return ToolOutput::error($refusal);
        }

        $this->beginReadOnlyTransaction();

        try {
            return $this->fetchRows($query, $parameters ?? []);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? null) !== self::READ_ONLY_VIOLATION) {
                throw $exception;
            }

            return ToolOutput::error('This tool is read-only. The database refused the query: ' . $exception->errorInfo[2]);
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    protected function refusal(string $query): ?string
    {
        if (!in_array($this->getFirstKeyword($query), $this->allowedStatements, true)) {
            return 'Start the query with one of these keywords, with nothing before it: ' . implode(', ', $this->allowedStatements) . '.';
        }

        if (preg_match('/;(?!\s*\z)/', $query) === 1) {
            return "Send one statement per call. Pass values that contain ';' as parameters.";
        }

        foreach ($this->fileAccessKeywords as $keyword) {
            if (stripos($query, (string) $keyword) !== false) {
                return 'Reading or writing server files (' . implode(', ', $this->fileAccessKeywords) . ') is not allowed.';
            }
        }

        return null;
    }

    /**
     * @throws ToolException
     */
    protected function beginReadOnlyTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new ToolException("{$this->name} cannot guarantee read-only access inside an open transaction: give it its own database connection.");
        }

        if ($this->pdo->exec('START TRANSACTION READ ONLY') === false) {
            throw new ToolException("{$this->name} could not start a read-only transaction.");
        }
    }

    /**
     * @param array<array{name: string, value: string}> $parameters
     */
    protected function fetchRows(string $query, array $parameters): array
    {
        $statement = $this->pdo->prepare($query);

        foreach ($parameters as $parameter) {
            $paramName = str_starts_with((string) $parameter['name'], ':') ? $parameter['name'] : ':' . $parameter['name'];
            $statement->bindValue($paramName, $parameter['value']);
        }

        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getFirstKeyword(string $query): string
    {
        if (preg_match('/^\s*(\w+)/', $query, $matches)) {
            return strtoupper($matches[1]);
        }
        return '';
    }
}
