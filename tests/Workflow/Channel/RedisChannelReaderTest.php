<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Closure;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChannelReadException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Persistence\Stub\RedisPersistenceFactory;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;
use NeuronAI\Workflow\Streaming\Channel\RedisChannelReader;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RuntimeException;

use function array_map;
use function array_sum;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function iterator_to_array;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function random_bytes;
use function serialize;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function unserialize;
use function usleep;

/**
 * A worker publishes from a forked process while the test reads, both on prefixed clients.
 */
class RedisChannelReaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The Redis channel reader tests publish from a forked process, which requires pcntl.');
        }
        // Skips without a Redis server; no connection may be open when the publisher forks.
        RedisPersistenceFactory::connect()->close();
    }

    protected function connect(): Redis
    {
        $redis = RedisPersistenceFactory::connect();
        $redis->setOption(Redis::OPT_PREFIX, 'neuron-test:');

        return $redis;
    }

    protected function channel(): string
    {
        return 'agent-run.' . bin2hex(random_bytes(8));
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    /**
     * @param Closure(Redis): void $publish
     */
    protected function publisher(Closure $publish): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                $publish($this->connect());
            } finally {
                exit(0);
            }
        }

        return $pid;
    }

    /**
     * @return list<string>
     */
    protected function frames(string $channel, int $publisher): array
    {
        $frames = [];
        try {
            (new RedisChannelReader($this->connect(), $channel, timeout: 10))->listen(function (ProtocolEvent $event) use (&$frames): void {
                $frames[] = SSEEncoder::frame($event);
            });
        } finally {
            pcntl_waitpid($publisher, $status);
        }

        return $frames;
    }

    public function test_relays_a_turn_as_the_pull_path_streamed_it(): void
    {
        $channel = $this->channel();
        $direct = tempnam(sys_get_temp_dir(), 'neuron-redis-reader-');
        $publisher = $this->publisher(function (Redis $redis) use ($channel, $direct): void {
            $agent = Agent::make()
                ->setThreadId('thread-1')
                ->setStreamAdapter(fn (): AGUIAdapter => new AGUIAdapter('thread-1', 'run-1'))
                ->setChannel(fn (): StreamingChannelInterface => new RedisChannel($redis, $channel, awaitListener: 5));
            $agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello from a queued worker')))->setStreamChunkSize(5));
            $events = $agent->events(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hi')], new AgentRunOptions(stream: true))));
            file_put_contents($direct, serialize(iterator_to_array(SSEEncoder::encode($events), false)));
        });

        $relayed = $this->frames($channel, $publisher);

        $this->assertSame(unserialize(file_get_contents($direct)), $relayed);
        unlink($direct);
    }

    /** @return array<string, array{Closure(RedisChannel, WorkflowState): void}> */
    public static function terminalProvider(): array
    {
        return [
            'completed' => [static fn (RedisChannel $channel, WorkflowState $state) => $channel->completed($state, 'wf-1')],
            'interrupted' => [static fn (RedisChannel $channel, WorkflowState $state) => $channel->interrupted($state)],
            'failed' => [static fn (RedisChannel $channel, WorkflowState $state) => $channel->failed(new RuntimeException('provider down'), 'wf-1')],
        ];
    }

    /**
     * @param Closure(RedisChannel, WorkflowState): void $end
     */
    #[DataProvider('terminalProvider')]
    public function test_empty_maps_arrive_as_objects_until_the_terminal_event(Closure $end): void
    {
        $channel = $this->channel();
        $events = [
            new ProtocolEvent('STATE_SNAPSHOT', ['snapshot' => (object) []]),
            new ProtocolEvent('tool-input-available', ['toolCallId' => 'call-1', 'toolName' => 'now', 'input' => (object) []]),
        ];
        $publisher = $this->publisher(function (Redis $redis) use ($channel, $events, $end): void {
            $publishing = new RedisChannel($redis, $channel, awaitListener: 5);
            foreach ($events as $event) {
                $publishing->send($event);
            }
            $end($publishing, $this->state());
        });

        $frames = $this->frames($channel, $publisher);

        $this->assertSame(array_map(SSEEncoder::frame(...), $events), $frames);
        $this->assertStringContainsString('"snapshot":{}', $frames[0]);
    }

    public function test_a_segment_replaced_before_its_terminal_event_is_refused(): void
    {
        $channel = $this->channel();
        $publisher = $this->publisher(function (Redis $redis) use ($channel): void {
            (new RedisChannel($redis, $channel, awaitListener: 5))->send(new ProtocolEvent('RUN_STARTED'));
            $recovered = new RedisChannel($redis, $channel, awaitListener: 5);
            $recovered->send(new ProtocolEvent('RUN_STARTED'));
            $recovered->completed($this->state(), 'wf-1');
        });

        try {
            $this->frames($channel, $publisher);
            $this->fail('Expected the replaced segment to be refused.');
        } catch (ChannelReadException $e) {
            $this->assertStringEndsWith('ended without a terminal event: another segment started.', $e->getMessage());
        }
    }

    public function test_a_segment_that_started_before_the_reader_subscribed_is_refused(): void
    {
        $channel = $this->channel();
        $publisher = $this->publisher(function (Redis $redis) use ($channel): void {
            $publishing = new RedisChannel($redis, $channel);
            $publishing->send(new ProtocolEvent('RUN_STARTED'));
            $redis->set("{$channel}:started", '1');
            while (array_sum($redis->pubsub('numsub', [$channel])) < 1) {
                usleep(10_000);
            }
            $publishing->send(new ProtocolEvent('TEXT_MESSAGE_CONTENT', ['delta' => 'late']));
            $publishing->completed($this->state(), 'wf-1');
        });
        $redis = $this->connect();
        while ($redis->get("{$channel}:started") === false) {
            usleep(10_000);
        }
        $redis->del("{$channel}:started");

        try {
            $this->frames($channel, $publisher);
            $this->fail('Expected the headless segment to be refused.');
        } catch (ChannelReadException $e) {
            $this->assertStringEndsWith('started before the reader subscribed.', $e->getMessage());
        }
    }

    public function test_gives_up_when_nothing_arrives_within_the_timeout(): void
    {
        $redis = $this->connect();
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 7);
        $channel = $this->channel();
        try {
            (new RedisChannelReader($redis, $channel, timeout: 1))->listen(function (ProtocolEvent $event): void {
            });
            $this->fail('Expected the reader to give up.');
        } catch (ChannelReadException $e) {
            $this->assertSame("Nothing arrived on Redis channel {$channel} within the 1-second timeout.", $e->getMessage());
            $this->assertInstanceOf(RedisException::class, $e->getPrevious());
        }

        $this->assertEquals(7, $redis->getOption(Redis::OPT_READ_TIMEOUT));
        $this->assertTrue($redis->ping());
    }

    public function test_a_failing_callback_ends_the_subscription_and_leaves_the_connection_usable(): void
    {
        $channel = $this->channel();
        $publisher = $this->publisher(function (Redis $redis) use ($channel): void {
            $publishing = new RedisChannel($redis, $channel, awaitListener: 5);
            for ($delta = 0; $delta < 500; ++$delta) {
                $publishing->send(new ProtocolEvent('TEXT_MESSAGE_CONTENT', ['delta' => (string) $delta]));
            }
            $publishing->completed($this->state(), 'wf-1');
        });
        $redis = $this->connect();
        $started = microtime(true);
        try {
            (new RedisChannelReader($redis, $channel, timeout: 10))->listen(function (ProtocolEvent $event): void {
                throw new RuntimeException('client went away');
            });
            $this->fail('Expected the callback failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('client went away', $e->getMessage());
        } finally {
            pcntl_waitpid($publisher, $status);
        }

        // Messages still in flight when the subscription ended must not answer later commands.
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertTrue($redis->set('reader-probe', 'ok'));
        $this->assertSame('ok', $redis->get('reader-probe'));
        $redis->del('reader-probe');
    }
}
