<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

/**
 * The session ended before the request reached the server: the server expired it, or its
 * process is gone. Nothing was processed, so McpClient opens a new session and sends the
 * request again. A custom transport throws it to get the same recovery.
 */
class McpSessionLostException extends McpException
{
}
