<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Closure;
use LogicException;
use NeuronAI\Workflow\Streaming\Channel\IQStreamChannel;

use function array_key_last;
use function count;
use function explode;
use function fclose;
use function fwrite;
use function hexdec;
use function implode;
use function rtrim;
use function str_ends_with;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function strpos;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * An IQStreamChannel whose connection is one end of a socket pair, with the test at the other end:
 * after every write, the test sees the request as written so far and decides what the channel
 * finds there next.
 */
class SocketPairIQStreamChannel extends IQStreamChannel
{
    /** @var list<string> */
    protected array $requests = [];

    /**
     * The test's end of the request in progress.
     *
     * @var resource|null
     */
    protected $peer;

    /**
     * @param list<Closure(string): ?string> $peers One per request, in order. Each is called after
     *                                              every write with the request so far, and returns
     *                                              the answer that ends it, an empty one to close
     *                                              it without a word, or null to leave it open.
     */
    public function __construct(protected array $peers, string $url, string $stream, string $token, int $maxFrameBytes = 65_536)
    {
        parent::__construct($url, $stream, $token, $maxFrameBytes);
    }

    /**
     * Every request, as the channel wrote it.
     *
     * @return list<string>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The head of a request, and the chunks of its body.
     *
     * @return array{string, list<string>}
     */
    public static function parse(string $request): array
    {
        [$head, $body] = explode("\r\n\r\n", $request, 2);
        $chunks = [];
        for ($offset = 0; ($end = strpos($body, "\r\n", $offset)) !== false && ($size = hexdec(substr($body, $offset, $end - $offset))) > 0;) {
            $chunks[] = substr($body, $end + 2, $size);
            $offset = $end + 2 + $size + 2;
        }

        return [$head, $chunks];
    }

    /**
     * The NDJSON lines of a request's body.
     *
     * @return list<string>
     */
    public static function lines(string $request): array
    {
        return explode("\n", rtrim(implode('', self::parse($request)[1]), "\n"));
    }

    /** Answers once the body has ended. */
    public static function answering(int $status = 200, string $body = '{}'): Closure
    {
        return static fn (string $request): ?string => str_ends_with($request, "\r\n0\r\n\r\n") ? self::response($status, $body) : null;
    }

    /** Answers the first bytes of the request, before its body ends. */
    public static function refusing(int $status, string $body): Closure
    {
        return static fn (): string => self::response($status, $body);
    }

    /** Closes the connection after the first bytes of the request, without an answer. */
    public static function hangingUp(): Closure
    {
        return static fn (): string => '';
    }

    protected static function response(int $status, string $body): string
    {
        return "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}";
    }

    protected function open(): string
    {
        if (!isset($this->peers[count($this->requests)])) {
            throw new LogicException('The channel opened a request the test did not expect.');
        }

        [$this->socket, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->peer, false);
        $this->requests[] = '';

        return $this->head;
    }

    protected function write(string $bytes): void
    {
        parent::write($bytes);

        $request = array_key_last($this->requests);
        $this->requests[$request] .= stream_get_contents($this->peer);
        $answer = $this->peers[$request]($this->requests[$request]);
        if ($answer !== null) {
            fwrite($this->peer, $answer);
            fclose($this->peer);
        }
    }
}
