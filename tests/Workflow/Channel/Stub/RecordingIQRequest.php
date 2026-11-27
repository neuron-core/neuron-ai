<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use IQStream\Answer;
use IQStream\Transport\OpenRequest;

/**
 * A held request to a RecordingIQTransport.
 */
class RecordingIQRequest implements OpenRequest
{
    protected int $writes = 0;

    protected bool $ended = false;

    public function __construct(
        protected RecordingIQTransport $server,
        protected int $request,
        protected ?Answer $early = null,
        protected ?int $letGoAfter = null,
    ) {
    }

    public function write(string $bytes): bool
    {
        if ($this->settled() || $this->ended) {
            return false;
        }
        $this->writes++;
        $this->server->take($this->request, $bytes);

        return true;
    }

    public function settled(): bool
    {
        if (!$this->early instanceof Answer && $this->letGoAfter !== null && $this->writes >= $this->letGoAfter) {
            $this->early = $this->server->state(408, ['error' => 'read_timeout']);
        }

        return $this->early instanceof Answer;
    }

    public function end(): void
    {
        $this->ended = true;
    }

    public function answer(float $timeout): Answer
    {
        if ($this->settled()) {
            return $this->early ?? Answer::none();
        }

        return $this->ended ? $this->server->state(200) : Answer::none('no answer in time');
    }

    public function destroy(): void
    {
        $this->early ??= Answer::none('dropped');
    }
}
