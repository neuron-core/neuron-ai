<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming;

use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function substr;

use const JSON_THROW_ON_ERROR;

class ProtocolEventTypeTest extends TestCase
{
    public function test_payload_data_cannot_replace_the_event_type_on_the_wire(): void
    {
        $event = new ProtocolEvent('tool-output', ['type' => 'finish', 'output' => 'result']);

        $frame = json_decode(substr(SSEEncoder::frame($event), 6), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('tool-output', $frame['type']);
        $this->assertSame('result', $frame['output']);
    }
}
