<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use Illuminate\Database\Capsule\Manager as Capsule;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\ChatMessage;
use NeuronAI\Tests\Chat\History\Stub\PostgresMessageStore;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class NegativeLimitTest extends TestCase
{
    protected string $directory;

    /**
     * @var PostgresMessageStore[]
     */
    protected static array $postgresStores = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_negative_limit_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (self::$postgresStores as $store) {
            $store->drop();
        }
        self::$postgresStores = [];

        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/{,.}*.chat*', GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    /**
     * @return array<string, array{0: callable(string): MessageStoreInterface}>
     */
    public static function stores(): array
    {
        return [
            'in-memory' => [fn (string $directory): MessageStoreInterface => new InMemoryMessageStore()],
            'file' => [fn (string $directory): MessageStoreInterface => new FileMessageStore($directory)],
            'sql' => [fn (string $directory): MessageStoreInterface => new SqliteMessageStore()],
            'eloquent' => [fn (string $directory): MessageStoreInterface => self::eloquentStore()],
            'pgsql' => [fn (string $directory): MessageStoreInterface => self::$postgresStores[] = PostgresMessageStore::open()],
        ];
    }

    protected static function eloquentStore(): EloquentMessageStore
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->statement(SqliteMessageStore::SCHEMA);

        return new EloquentMessageStore(ChatMessage::class);
    }

    #[DataProvider('stores')]
    public function test_a_negative_limit_yields_no_messages_like_a_limit_of_zero(callable $make): void
    {
        $store = $make($this->directory);
        $store->append('thread', new UserMessage('1'));
        $store->append('thread', $last = new AssistantMessage('2'));

        $this->assertSame([], $store->loadAll('thread', limit: -1));
        $this->assertSame([], $store->loadAll('thread', limit: -1, before: $last->getId()));
    }
}
