<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function file_put_contents;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class TokenCounterImageFetchTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingStreamWrapper::$opened = [];
        stream_wrapper_register('probe', RecordingStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('probe');
    }

    public function test_counting_tokens_never_dereferences_an_image_url(): void
    {
        // A user-supplied URL, e.g. http://169.254.169.254/latest/meta-data/ in production.
        $message = new UserMessage([new ImageContent('probe://internal-host/secret.png', SourceType::URL, 'image/png')]);

        (new TokenCounter())->count($message);

        $this->assertSame([], RecordingStreamWrapper::$opened);
    }

    public function test_appending_to_history_never_dereferences_an_image_url(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');

        $history->addMessage(new UserMessage([new ImageContent('probe://internal-host/secret.png', SourceType::URL, 'image/png')]));

        $this->assertSame([], RecordingStreamWrapper::$opened);
    }

    public function test_an_image_id_is_not_resolved_as_a_local_file(): void
    {
        $path = sys_get_temp_dir() . '/token-counter-probe-' . uniqid() . '.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        try {
            $counter = new TokenCounter();
            $existing = $counter->count(new UserMessage([new ImageContent($path, SourceType::ID)]));
            $missing = $counter->count(new UserMessage([new ImageContent($path . '.missing.png', SourceType::ID)]));

            $this->assertSame($missing, $existing, 'The token count reveals whether a server-side path exists');
        } finally {
            unlink($path);
        }
    }

    public function test_a_base64_payload_that_is_not_an_image_is_counted_without_error(): void
    {
        $image = new ImageContent('bm90IGFuIGltYWdl', SourceType::BASE64, 'image/png');

        $this->assertGreaterThanOrEqual(1, (new TokenCounter())->count(new UserMessage([$image])));
    }
}

class RecordingStreamWrapper
{
    /** @var string[] */
    public static array $opened = [];

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = $path;
        return false;
    }
}
