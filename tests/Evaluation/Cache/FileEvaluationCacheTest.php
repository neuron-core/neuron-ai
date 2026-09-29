<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Cache;

use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function glob;
use function hash;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function serialize;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileEvaluationCacheTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    protected function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }

        if (is_dir($path)) {
            array_map($this->remove(...), glob($path . '/*') ?: []);
            rmdir($path);
        }
    }

    protected function entryPath(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }

    protected function plantEntry(string $key, string $contents): void
    {
        mkdir($this->directory, 0o755, true);
        file_put_contents($this->entryPath($key), $contents);
    }

    public function test_missing_key_is_absent(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $this->assertFalse($cache->has('missing'));
        $this->assertNull($cache->get('missing'));
    }

    public function test_set_get_round_trip(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key1', 'a string output');
        $cache->set('key2', ['nested' => ['data' => 42]]);

        $this->assertTrue($cache->has('key1'));
        $this->assertSame('a string output', $cache->get('key1'));
        $this->assertSame(['nested' => ['data' => 42]], $cache->get('key2'));
    }

    public function test_overwrite_replaces_value(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'first');
        $cache->set('key', 'second');

        $this->assertSame('second', $cache->get('key'));
    }

    public function test_non_serializable_value_is_silently_skipped(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', fn (): string => 'not serializable');

        $this->assertFalse($cache->has('key'));
    }

    public function test_creates_the_missing_directory_tree_on_first_write(): void
    {
        $cache = new FileEvaluationCache($this->directory . '/nested/evaluation');

        $cache->set('key', 'output');

        $this->assertTrue($cache->has('key'));
        $this->assertFileExists($this->directory . '/nested/evaluation/' . hash('sha256', 'key') . '.cache');
    }

    public function test_write_leaves_no_temporary_files_behind(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'first');
        $cache->set('key', 'second');

        $this->assertSame([$this->entryPath('key')], glob($this->directory . '/*'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function falsyOutputs(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'zero' => [0];
        yield 'empty array' => [[]];
    }

    #[DataProvider('falsyOutputs')]
    public function test_falsy_outputs_are_cached_values(mixed $output): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', $output);

        $this->assertTrue($cache->has('key'));
        $this->assertSame($output, $cache->get('key'));
    }

    public function test_trajectory_output_round_trips(): void
    {
        $tool = ToolCall::make('refund_order', 'call_1', ['order_id' => '123']);
        $tool->setApprovalState(ApprovalState::Rejected, 'too expensive');
        $tool->setResult('rejected by the user');
        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 123'),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('I cannot refund it.'),
        ]);
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', $trajectory);
        $restored = $cache->get('key');

        $this->assertInstanceOf(Trajectory::class, $restored);
        $this->assertSame($trajectory->toTranscript(), $restored->toTranscript());
    }

    public function test_keys_are_isolated_from_each_other(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('a', 'output a');

        $this->assertFalse($cache->has('b'));
        $this->assertFalse($cache->has('a.cache'));
        $this->assertSame('output a', $cache->get('a'));
    }

    public function test_unwritable_location_is_a_silent_miss(): void
    {
        // The configured directory is an existing file: it can be neither created nor written
        file_put_contents($this->directory, 'not a directory');
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'output');

        $this->assertFalse($cache->has('key'));
        $this->assertNull($cache->get('key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableEntries(): iterable
    {
        yield 'truncated' => [substr(serialize(['output' => 'agent output']), 0, 20)];
        yield 'garbage' => ['not a serialized value'];
        yield 'empty file' => [''];
        yield 'serialized false without envelope' => [serialize(false)];
        yield 'value without envelope' => [serialize('agent output')];
    }

    #[DataProvider('unreadableEntries')]
    public function test_an_unreadable_entry_is_a_miss(string $contents): void
    {
        $this->plantEntry('key', $contents);
        $cache = new FileEvaluationCache($this->directory);

        $this->assertFalse($cache->has('key'));
        $this->assertNull($cache->get('key'));
    }

    public function test_a_miss_on_an_unreadable_entry_is_repaired_by_the_next_write(): void
    {
        $this->plantEntry('key', 'garbage');
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'agent output');

        $this->assertSame('agent output', $cache->get('key'));
    }

    public function test_a_traversal_key_stays_inside_the_directory(): void
    {
        $cache = new FileEvaluationCache($this->directory . '/cache');

        $cache->set('../escaped', 'agent output');

        $this->assertFileDoesNotExist($this->directory . '/escaped.cache');
        $this->assertSame([$this->directory . '/cache'], glob($this->directory . '/*'));
        $this->assertSame('agent output', $cache->get('../escaped'));
    }

    public function test_any_string_is_a_usable_key(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('orders/42 refund?', 'agent output');

        $this->assertSame('agent output', $cache->get('orders/42 refund?'));
    }
}
