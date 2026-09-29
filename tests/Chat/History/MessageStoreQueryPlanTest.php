<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\RecordingStatement;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function str_contains;
use function str_repeat;

/**
 * MySQL filesorts rows found through the unique (thread_id, message_id) index and copies
 * every selected column into its sort buffer: a query reading content must never sort.
 */
class MessageStoreQueryPlanTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function servers(): array
    {
        return [
            'mysql' => [3306],
            'mariadb' => [3307],
        ];
    }

    #[DataProvider('servers')]
    public function test_the_sql_store_never_sorts_rows_holding_content(int $port): void
    {
        $pdo = $this->database($port);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::$executed = [];

        $this->loadEveryWay(new SQLMessageStore($pdo));

        $this->assertNoFilesortReadingContent(RecordingStatement::$executed, function (string $query, array $parameters) use ($pdo): array {
            $stmt = $pdo->prepare("EXPLAIN {$query}");
            $stmt->execute($parameters);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    #[DataProvider('servers')]
    public function test_the_eloquent_store_never_sorts_rows_holding_content(int $port): void
    {
        $this->database($port);

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => 'neuron-ai',
            'username' => 'root',
            'password' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $connection = $capsule->getConnection();
        $connection->enableQueryLog();

        $this->loadEveryWay(new EloquentMessageStore(ChatMessage::class));

        $executed = array_map(fn (array $entry): array => [$entry['query'], $entry['bindings']], $connection->getQueryLog());
        $this->assertNoFilesortReadingContent($executed, fn (string $query, array $bindings): array => array_map(
            fn (object $row): array => (array) $row,
            $connection->select("EXPLAIN {$query}", $bindings)
        ));
    }

    protected function loadEveryWay(MessageStoreInterface $store): void
    {
        $cursor = $store->loadAll('thread', limit: 1)[0]->getId();

        $store->loadActive('thread');
        $store->loadAll('thread');
        $store->loadAll('thread', before: $cursor);
        $store->loadAll('thread', limit: 5);
        $store->loadAll('thread', limit: 5, before: $cursor);
    }

    /**
     * @param array<int, array{string, array<int|string, mixed>}> $executed
     * @param callable(string, array<int|string, mixed>): array<int, array<string, mixed>> $explain
     */
    protected function assertNoFilesortReadingContent(array $executed, callable $explain): void
    {
        $readingContent = array_filter($executed, fn (array $entry): bool => str_contains($entry[0], 'content'));
        $this->assertNotEmpty($readingContent);

        foreach ($readingContent as [$query, $parameters]) {
            foreach ($explain($query, $parameters) as $step) {
                $this->assertStringNotContainsString('filesort', (string) $step['Extra'], $query);
            }
        }
    }

    /**
     * The table documented on SQLMessageStore, holding a busy thread among others.
     */
    protected function database(int $port): PDO
    {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname=neuron-ai", 'root', '');
        } catch (PDOException) {
            $this->markTestSkipped("MySQL not available on port {$port}.");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('DROP TABLE IF EXISTS chat_messages');
        $pdo->exec('CREATE TABLE chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id VARBINARY(255) NOT NULL,
            message_id VARBINARY(64) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content LONGTEXT NULL,
            meta LONGTEXT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_thread_message (thread_id, message_id)
        )');

        $store = new SQLMessageStore($pdo);
        foreach (['thread', 'other', 'another'] as $thread) {
            for ($index = 0; $index < 50; $index++) {
                $store->append($thread, new UserMessage(str_repeat('x', 1000)));
            }
        }
        $store->archive('thread', 10);
        $pdo->query('ANALYZE TABLE chat_messages')->fetchAll();

        return $pdo;
    }
}
