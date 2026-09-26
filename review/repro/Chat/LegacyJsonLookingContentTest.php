<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Messages\MessageDeserializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LegacyJsonLookingContentTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function texts(): array
    {
        return [
            'boolean word' => ['true'],
            'quoted' => ['"quoted"'],
            'scientific number' => ['1e3'],
            'json list' => ['[1,2]'],
            'json object' => ['{"answer":42}'],
        ];
    }

    #[DataProvider('texts')]
    public function test_legacy_plain_text_is_kept_verbatim(string $text): void
    {
        $restored = (new MessageDeserializer())->deserialize(['role' => 'user', 'content' => $text]);

        $this->assertSame($text, $restored->getContent());
    }

    public function test_legacy_content_encoded_as_a_json_string_is_still_decoded(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'content' => '[{"type":"text","content":"Encoded twice"}]',
        ]);

        $this->assertSame('Encoded twice', $restored->getContent());
    }
}
