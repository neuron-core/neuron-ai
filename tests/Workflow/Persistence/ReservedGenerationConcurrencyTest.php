<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_filter;
use function fclose;
use function fgets;
use function function_exists;
use function fwrite;
use function is_resource;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function stream_set_timeout;
use function stream_socket_pair;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

class ReservedGenerationConcurrencyTest extends TestCase
{
    public function test_competing_processes_cannot_initialize_different_generations_at_one_address(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The process ownership check requires pcntl.');
        }
        $path = tempnam(sys_get_temp_dir(), 'neuron-reservation-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE workflow_store ("partition" TEXT, "key" TEXT, "value" TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY ("partition", "key"))');
        unset($pdo);
        $workers = [];
        try {
            foreach (['first', 'second'] as $runId) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($sockets[0]);
                    $pdo = new PDO('sqlite:'.$path);
                    $pdo->exec('PRAGMA busy_timeout = 5000');
                    fwrite($sockets[1], "ready\n");
                    fgets($sockets[1]);
                    try {
                        $result = Workflow::make('shared')->setPersistence(new DatabasePersistence($pdo))
                            ->addNode(new MemoizingNode())->retainCompletionUntilAcknowledged()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new StartEvent(), $runId));
                        $outcome = ['runId' => $result->getRunId()];
                    } catch (WorkflowException) {
                        $outcome = ['refused' => true];
                    } catch (Throwable $e) {
                        $outcome = ['error' => $e->getMessage()];
                    }
                    fwrite($sockets[1], json_encode($outcome, JSON_THROW_ON_ERROR)."\n");
                    fclose($sockets[1]);
                    exit(0);
                }
                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 10);
                $workers[] = [$pid, $sockets[0]];
            }
            foreach ($workers as [$pid, $socket]) {
                self::assertSame("ready\n", fgets($socket));
            }
            foreach ($workers as [$pid, $socket]) {
                fwrite($socket, "go\n");
            }
            $results = [];
            foreach ($workers as [$pid, $socket]) {
                $results[] = json_decode(fgets($socket), true, flags: JSON_THROW_ON_ERROR);
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
                fclose($socket);
            }
            self::assertCount(1, array_filter($results, fn (array $result): bool => isset($result['runId'])));
            self::assertCount(1, array_filter($results, fn (array $result): bool => isset($result['refused'])));
            $store = new DatabasePersistence(new PDO('sqlite:'.$path));
            $winner = Workflow::make('shared')->setPersistence($store)->inspect()->runId;
            self::assertSame($winner, (new PhpSerializer())->unserialize($store->get('shared', '__ignition'))->runId);
        } finally {
            foreach ($workers as [$pid, $socket]) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
                pcntl_waitpid($pid, $status);
            }
            unlink($path);
        }
    }
}
