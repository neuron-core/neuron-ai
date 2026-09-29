<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP\Stub;

use NeuronAI\MCP\StdioTransport;

/**
 * Gives a server little time to exit at each step of the shutdown, so tests of a server that
 * does not exit on its own stay fast.
 */
class ShortGraceStdioTransport extends StdioTransport
{
    protected const EXIT_GRACE_SECONDS = 0.2;
}
