<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function iterator_to_array;
use function json_decode;
use function json_encode;

class AGUIErrorCodeRedactionTest extends TestCase
{
    public function test_run_error_does_not_leak_a_pdo_sqlstate_by_default(): void
    {
        $failure = $this->databaseFailure();
        $this->assertSame('HY000', $failure->getCode());

        $frames = $this->failWith(new AGUIAdapter('thread_test', 'run_test'), $failure);

        $this->assertSame(['type' => 'RUN_ERROR', 'message' => 'The run failed.'], $frames);
    }

    public function test_run_error_does_not_leak_a_provider_status_by_default(): void
    {
        $frames = $this->failWith(new AGUIAdapter('thread_test', 'run_test'), new RuntimeException('upstream', 503));

        $this->assertSame(['type' => 'RUN_ERROR', 'message' => 'The run failed.'], $frames);
    }

    public function test_an_application_redacting_the_message_can_also_decide_the_code(): void
    {
        $adapter = new class ('thread_test', 'run_test') extends AGUIAdapter {
            protected function errorMessage(Throwable $error): string
            {
                return 'Service unavailable.';
            }
        };

        $frames = $this->failWith($adapter, new RuntimeException('Connection refused to 10.0.0.12:5432', 111));

        $this->assertSame(['type' => 'RUN_ERROR', 'message' => 'Service unavailable.'], $frames);
    }

    protected function databaseFailure(): PDOException
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $pdo->query('SELECT * FROM secrets');
        } catch (PDOException $failure) {
            return $failure;
        }

        $this->fail('The query against a missing table must fail.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function failWith(AGUIAdapter $adapter, Throwable $failure): array
    {
        iterator_to_array($adapter->start(), false);
        $events = iterator_to_array($adapter->error($failure), false);
        $this->assertCount(1, $events);

        return json_decode(json_encode($events[0]), true);
    }
}
