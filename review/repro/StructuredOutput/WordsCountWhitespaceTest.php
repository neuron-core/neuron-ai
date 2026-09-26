<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\WordsCount;
use PHPUnit\Framework\TestCase;

class WordsCountWhitespaceTest extends TestCase
{
    public function test_tab_separated_words_are_counted(): void
    {
        $violations = [];
        (new WordsCount(max: 1))->validate('title', "one\ttwo", $violations);

        $this->assertSame(['title is too long. It must be at most 1 words'], $violations);
    }

    public function test_non_breaking_space_separated_words_are_counted(): void
    {
        $violations = [];
        (new WordsCount(max: 1))->validate('title', "one\u{00A0}two", $violations);

        $this->assertSame(['title is too long. It must be at most 1 words'], $violations);
    }

    public function test_exact_count_messages_are_consistent(): void
    {
        $tooFew = [];
        (new WordsCount(exactly: 3))->validate('title', 'one two', $tooFew);
        $tooMany = [];
        (new WordsCount(exactly: 3))->validate('title', 'one two three four', $tooMany);

        $this->assertSame(['title must have exactly 3 words'], $tooFew);
        $this->assertSame(['title must have exactly 3 words'], $tooMany);
    }
}
