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
use function is_dir;
use function is_file;
use function rmdir;
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
        $this->assertFileExists($this->directory . '/nested/evaluation/key.cache');
    }

    public function test_write_leaves_no_temporary_files_behind(): void
    {
        $cache = new FileEvaluationCache($this->directory);

        $cache->set('key', 'first');
        $cache->set('key', 'second');

        $this->assertSame([$this->directory . '/key.cache'], glob($this->directory . '/*'));
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
}
