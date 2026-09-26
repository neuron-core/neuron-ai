<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function glob;
use function is_dir;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class FileMessageStoreLongThreadIdTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_long_thread_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function threadIdsWithinVarchar255(): array
    {
        return [
            '100 CJK characters' => [str_repeat('線', 100)],
            '255 ASCII characters' => [str_repeat('a', 255)],
        ];
    }

    #[DataProvider('threadIdsWithinVarchar255')]
    public function test_round_trips_thread_ids_the_sql_store_accepts(string $threadId): void
    {
        $store = new FileMessageStore($this->directory);
        $message = new UserMessage('hello');

        $store->append($threadId, $message);

        $loaded = $store->loadActive($threadId);
        $this->assertCount(1, $loaded);
        $this->assertSame($message->getId(), $loaded[0]->getId());
        $this->assertSame([], $store->loadActive($threadId . 'x'));
    }
}
