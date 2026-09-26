<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlSchemeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function nonWebUrls(): array
    {
        return [
            'javascript with fake authority' => ['javascript://alert(1)'],
            'javascript newline payload' => ["javascript://x/%0Aalert(1)"],
            'local file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1:6379/_FLUSHALL'],
            'data' => ['data://text/plain;base64,SGVsbG8='],
        ];
    }

    #[DataProvider('nonWebUrls')]
    public function test_url_rejects_non_web_schemes_by_default(string $value): void
    {
        $violations = [];

        (new Url())->validate('field', $value, $violations);

        $this->assertSame(['field must be a valid URL'], $violations);
    }

    public function test_url_accepts_http_and_https(): void
    {
        $violations = [];

        (new Url())->validate('field', 'http://inspector.dev', $violations);
        (new Url())->validate('field', 'HTTPS://inspector.dev/path', $violations);

        $this->assertSame([], $violations);
    }
}
