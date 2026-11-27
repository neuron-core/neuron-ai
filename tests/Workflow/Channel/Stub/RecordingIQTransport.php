<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use IQStream\Answer;
use IQStream\StreamId;
use IQStream\Transport\OpenRequest;
use IQStream\Transport\Transport;

use function array_shift;
use function count;
use function explode;
use function is_int;
use function json_decode;
use function str_ends_with;
use function substr;

/**
 * One IQ stream behind the client's Transport interface: it keeps every line the channel's publisher
 * writes, in the order its requests took them, and answers as the server does.
 *
 * The script says what happens to each request in turn, short ones and held ones alike: null for the
 * server's usual answer, an Answer for one given at once, before any of the body, and an int for a
 * held request the server lets go of with 408 after that many writes, as after a silence.
 */
class RecordingIQTransport implements Transport
{
    /** @var list<array{continues: ?int, body: string}> */
    public array $requests = [];

    /** @var list<string> The data lines the stream holds. */
    public array $frames = [];

    public ?string $closedWith = null;

    /** @var list<Answer|int|null> */
    protected array $script;

    public function __construct(Answer|int|null ...$script)
    {
        $this->script = $script;
    }

    public function open(StreamId $stream, string $token, ?int $continues = null): OpenRequest
    {
        $this->requests[] = ['continues' => $continues, 'body' => ''];
        $behaviour = array_shift($this->script);
        $early = $behaviour instanceof Answer ? $behaviour : $this->refusal();

        return new RecordingIQRequest($this, count($this->requests) - 1, $early, is_int($behaviour) ? $behaviour : null);
    }

    public function post(StreamId $stream, string $token, string $body, float $timeout, ?int $continues = null): Answer
    {
        $this->requests[] = ['continues' => $continues, 'body' => ''];
        $behaviour = array_shift($this->script);
        $early = $behaviour instanceof Answer ? $behaviour : $this->refusal();
        if ($early instanceof Answer) {
            return $early;
        }
        $this->take(count($this->requests) - 1, $body);

        return $this->state(200);
    }

    /**
     * Every line the requests took, the close line included, without keepalives.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->requests as $request) {
            foreach (explode("\n", $request['body']) as $line) {
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Takes the whole lines of a body: frames until a close line.
     *
     * @internal
     */
    public function take(int $request, string $bytes): void
    {
        $this->requests[$request]['body'] .= $bytes;
        $lines = explode("\n", str_ends_with($bytes, "\n") ? substr($bytes, 0, -1) : $bytes);
        foreach ($lines as $line) {
            if ($line === '' || $this->closedWith !== null) {
                continue;
            }
            /** @var array{close?: string} $member */
            $member = json_decode($line, true);
            if (isset($member['close'])) {
                $this->closedWith = $member['close'];
                continue;
            }
            $this->frames[] = $line;
        }
    }

    /**
     * The stream's state, as the server answers with it.
     *
     * @param array<string, mixed> $extra
     * @internal
     */
    public function state(int $status, array $extra = []): Answer
    {
        $body = ['stream' => 'recorded', 'lastEventId' => count($this->frames), 'closed' => $this->closedWith !== null];
        if ($this->closedWith !== null) {
            $body['outcome'] = $this->closedWith;
        }

        return new Answer($status, $body + $extra);
    }

    /** A closed stream refuses every request before its body. */
    protected function refusal(): ?Answer
    {
        return $this->closedWith === null ? null : new Answer(409, ['error' => 'stream_closed', 'outcome' => $this->closedWith]);
    }
}
