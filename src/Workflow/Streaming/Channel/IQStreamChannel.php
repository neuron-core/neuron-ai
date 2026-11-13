<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function dechex;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function str_ends_with;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function strtok;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STREAM_CLIENT_CONNECT;

/**
 * Publishes a segment to an IQ streaming server over one chunked POST, held open from the first
 * event to the end of the segment: each envelope is an NDJSON line written as it is produced, and
 * the terminal one is followed by the line that closes the stream.
 *
 * The server lets go of a request that sends nothing for too long, as when a tool runs, but keeps
 * the stream open for a while: the next event continues it over a new request.
 */
class IQStreamChannel extends AbstractChannel
{
    /** Seconds to connect, to hand the server a line, and to wait for its answer. */
    protected const TIMEOUT = 10;

    protected const CLOSE_END = '{"close":"end"}' . "\n";

    protected const CLOSE_FAIL = '{"close":"fail"}' . "\n";

    /** The socket address: transport, host and port. */
    protected string $address;

    /** The head of every publish request of the segment. */
    protected string $head;

    /**
     * The publish request in progress.
     *
     * @var resource|null
     */
    protected $socket;

    /**
     * @param string $url The server's URL, such as https://stream.example.com.
     * @param string $stream The stream subscribers read. A closed stream takes no more frames:
     *                       every segment, a resumed one included, publishes to a new one.
     * @param string $token A pub token for the stream, valid for as long as the segment runs.
     * @param int $maxFrameBytes The longest line the server takes from the app, in bytes.
     */
    public function __construct(
        string $url,
        protected string $stream,
        string $token,
        protected int $maxFrameBytes = 65_536,
    ) {
        if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $stream) !== 1) {
            throw new InvalidArgumentException('IQ stream IDs are 16 to 128 letters, digits, underscores and hyphens.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The IQ server URL must be an absolute http or https URL.');
        }

        $secure = $parts['scheme'] === 'https';
        $this->address = ($secure ? 'tls://' : 'tcp://') . $parts['host'] . ':' . ($parts['port'] ?? ($secure ? 443 : 80));
        $this->head = 'POST ' . rtrim($parts['path'] ?? '', '/') . "/{$stream} HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : '') . "\r\n"
            . "Authorization: Bearer {$token}\r\n"
            . "Content-Type: application/x-ndjson\r\n"
            . "Transfer-Encoding: chunked\r\n"
            // One request per connection, so the answer ends where the connection does.
            . "Connection: close\r\n\r\n";
    }

    /** The longest line the server takes, measured here with its newline: a byte to spare. */
    protected function budget(): int
    {
        return $this->maxFrameBytes;
    }

    /**
     * A data line without an event name, so every frame reaches a subscriber's `message` listener.
     * A terminal event closes the stream after its own line: an interrupted segment ended as it should.
     */
    protected function encode(string $type, string $envelope): string
    {
        return '{"data":' . json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "}\n" . match ($type) {
            'stream.completed', 'stream.interrupted' => self::CLOSE_END,
            'stream.failed' => self::CLOSE_FAIL,
            default => '',
        };
    }

    protected function deliver(string $batch): void
    {
        try {
            $request = ($this->accepting() ? '' : $this->open()) . dechex(strlen($batch)) . "\r\n{$batch}\r\n";
            if (!$this->closes($batch)) {
                $this->write($request);
                return;
            }

            $this->write("{$request}0\r\n\r\n");
            $answer = $this->answer();
            if (preg_match('#\AHTTP/\d\.\d 200\b#', $answer) !== 1) {
                throw $this->refusal($answer);
            }
        } catch (Throwable $e) {
            // A request broken off mid-line cannot carry another one: the next delivery opens a new request.
            $this->disconnect();
            throw $e;
        }

        $this->disconnect();
    }

    /** A data line cannot end like a close line: the quotes of its data are escaped. */
    protected function closes(string $batch): bool
    {
        return str_ends_with($batch, self::CLOSE_END) || str_ends_with($batch, self::CLOSE_FAIL);
    }

    /**
     * Whether the request in progress takes more lines. The server answers before the body ends to
     * refuse the rest, or to let go of an idle request with `read_timeout`: a new request then
     * continues the stream, as it does after a close without an answer.
     */
    protected function accepting(): bool
    {
        if ($this->socket === null) {
            return false;
        }

        $read = [$this->socket];
        $none = null;
        if (@stream_select($read, $none, $none, 0) === 0) {
            return true;
        }

        // TLS records such as session tickets make the socket readable too: look without waiting.
        stream_set_blocking($this->socket, false);
        $byte = (string) @fread($this->socket, 1);
        stream_set_blocking($this->socket, true);
        if ($byte === '' && !stream_get_meta_data($this->socket)['eof']) {
            return true;
        }

        $answer = $byte === '' ? '' : $byte . $this->answer();
        if ($answer !== '' && !$this->letGo($answer)) {
            throw $this->refusal($answer);
        }

        $this->disconnect();
        return false;
    }

    /** The server's answer to a request it let go of for sending nothing: the stream stays open. */
    protected function letGo(string $answer): bool
    {
        $body = json_decode(explode("\r\n\r\n", $answer, 2)[1] ?? '', true);

        return is_array($body) && ($body['error'] ?? null) === 'read_timeout';
    }

    /**
     * Connects with Nagle's algorithm off, so a short line leaves at once instead of waiting for
     * the previous one to be acknowledged. Returns the request head, sent with the first line.
     */
    protected function open(): string
    {
        // PHP gives the reason a TLS handshake failed only as a warning.
        $warnings = [];
        set_error_handler(static function (int $level, string $warning) use (&$warnings): bool {
            $warnings[] = $warning;
            return true;
        });
        try {
            $socket = stream_socket_client(
                $this->address,
                $code,
                $message,
                self::TIMEOUT,
                STREAM_CLIENT_CONNECT,
                stream_context_create(['socket' => ['tcp_nodelay' => true]]),
            );
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            throw new RuntimeException("IQ streaming could not connect to {$this->address}: " . ($message !== '' ? $message : $warnings[0] ?? 'unknown error'));
        }

        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;

        return $this->head;
    }

    protected function write(string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($this->socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException(stream_get_meta_data($this->socket)['timed_out']
                    ? 'IQ streaming stopped: the server took nothing for ' . self::TIMEOUT . ' seconds.'
                    : 'IQ streaming stopped: the connection to the server broke.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** The server's answer, read to the end of the connection. */
    protected function answer(): string
    {
        $answer = (string) @stream_get_contents($this->socket);
        if (stream_get_meta_data($this->socket)['timed_out']) {
            throw new RuntimeException('IQ streaming stopped: the server did not answer within ' . self::TIMEOUT . ' seconds.');
        }

        return $answer;
    }

    /** Why the server stopped the stream: its status line, with its JSON body when it has one. */
    protected function refusal(string $answer): RuntimeException
    {
        [$head, $body] = explode("\r\n\r\n", $answer, 2) + [1 => ''];
        $status = $answer === '' ? 'no answer' : strtok($head, "\r\n");
        $details = is_array(json_decode($body, true)) ? " {$body}" : '';

        return new RuntimeException("IQ stream {$this->stream} stopped: {$status}{$details}");
    }

    protected function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
