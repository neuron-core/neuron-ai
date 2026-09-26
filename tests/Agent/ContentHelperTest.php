<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\ContentHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentHelperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function delimitedContent(): iterable
    {
        yield 'single block' => ['<think>plan</think>Answer', '<think>', '</think>', 'Answer'];
        yield 'multiline block' => ["<think>line 1\nline 2</think>Answer", '<think>', '</think>', 'Answer'];
        yield 'every block, not greedy across them' => ['<t>a</t>keep<t>b</t> me', '<t>', '</t>', 'keep me'];
        yield 'empty block' => ['A<think></think>B', '<think>', '</think>', 'AB'];
        yield 'no block' => ['Plain answer', '<think>', '</think>', 'Plain answer'];
        yield 'unclosed block is kept' => ['<think>never closed', '<think>', '</think>', '<think>never closed'];
        yield 'multibyte content' => ['<think>perché 🚀</think>Risposta è pronta', '<think>', '</think>', 'Risposta è pronta'];
        yield 'empty text' => ['', '<think>', '</think>', ''];
    }

    #[DataProvider('delimitedContent')]
    public function test_it_removes_every_delimited_block(string $text, string $open, string $close, string $expected): void
    {
        $this->assertSame($expected, ContentHelper::removeDelimitedContent($text, $open, $close));
    }

    /**
     * Delimiters are literal text: regex metacharacters in them must not
     * change what is matched or break the pattern.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function metacharacterDelimiters(): iterable
    {
        yield 'dot and star' => ['a.*b', '.*', '*.', 'a.*b'];
        yield 'brackets' => ['keep [x] drop[[secret]]', '[[', ']]', 'keep [x] drop'];
        yield 'slash delimiter' => ['/*comment*/code', '/*', '*/', 'code'];
        yield 'pipes' => ['a||hidden||b', '||', '||', 'ab'];
        yield 'dollar and caret' => ['^$x$^ y', '^$', '$^', ' y'];
    }

    #[DataProvider('metacharacterDelimiters')]
    public function test_delimiters_are_matched_literally(string $text, string $open, string $close, string $expected): void
    {
        $this->assertSame($expected, ContentHelper::removeDelimitedContent($text, $open, $close));
    }
}
