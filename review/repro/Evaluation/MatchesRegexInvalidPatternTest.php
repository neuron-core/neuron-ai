<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\MatchesRegex;
use PHPUnit\Framework\TestCase;

class MatchesRegexInvalidPatternTest extends TestCase
{
    public function test_an_invalid_pattern_is_a_coding_error_not_a_failed_verdict(): void
    {
        $assertion = new MatchesRegex('/unterminated');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('/unterminated');

        $assertion->evaluate('any agent output');
    }

    public function test_a_regex_engine_failure_is_not_a_failed_verdict(): void
    {
        // Catastrophic backtracking exhausts pcre.backtrack_limit: preg_match() returns false
        $assertion = new MatchesRegex('/(?:\D+|<\d+>)*[!?]/');

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate('foobar foobar foobar foobar foobar foobar foobar foobar');
    }

    public function test_a_valid_pattern_still_passes_and_fails_on_the_output(): void
    {
        $this->assertTrue((new MatchesRegex('/^order \d+$/'))->evaluate('order 42')->passed);
        $this->assertFalse((new MatchesRegex('/^order \d+$/'))->evaluate('order x')->passed);
    }
}
