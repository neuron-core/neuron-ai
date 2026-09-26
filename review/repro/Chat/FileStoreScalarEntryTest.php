<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Exceptions\ChatHistoryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileStoreScalarEntryTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_scalar_' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/neuron_thread.chat');
        rmdir($this->directory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonObjectEntries(): array
    {
        return [
            'integer entry' => ['[1]'],
            'string entry' => ['["x"]'],
            'single message object as root' => ['{"role":"user","content":"hi"}'],
        ];
    }

    #[DataProvider('nonObjectEntries')]
    public function test_a_file_of_non_object_entries_is_reported_as_corrupt(string $content): void
    {
        file_put_contents($this->directory . '/neuron_thread.chat', $content);
        $store = new FileMessageStore($this->directory);

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("The chat history file '{$this->directory}/neuron_thread.chat' is corrupt.");

        $store->loadActive('thread');
    }
}
