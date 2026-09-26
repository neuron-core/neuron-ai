<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\StringContainsAny;
use PHPUnit\Framework\TestCase;

class StringContainsMultibyteCaseTest extends TestCase
{
    public function test_string_contains_matches_non_ascii_letters_case_insensitively(): void
    {
        $this->assertTrue((new StringContains('ÉCOLE'))->evaluate('Une école à Paris')->passed);
    }

    public function test_string_contains_all_matches_non_ascii_letters_case_insensitively(): void
    {
        $this->assertTrue((new StringContainsAll(['ZÜRICH', 'CAFÉ']))->evaluate('zürich café')->passed);
    }

    public function test_string_contains_any_matches_non_ascii_letters_case_insensitively(): void
    {
        $this->assertTrue((new StringContainsAny(['ÜBER']))->evaluate('über alles')->passed);
    }

    public function test_non_ascii_keyword_still_fails_when_absent(): void
    {
        $this->assertFalse((new StringContains('ÉCOLE'))->evaluate('Une maison à Paris')->passed);
    }
}
