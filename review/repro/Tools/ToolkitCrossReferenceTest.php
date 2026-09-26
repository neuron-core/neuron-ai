<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use PHPUnit\Framework\TestCase;

use function array_map;
use function preg_match_all;

class ToolkitCrossReferenceTest extends TestCase
{
    public function test_descriptions_only_reference_tools_the_toolkit_provides(): void
    {
        $toolkit = FileSystemToolkit::make();
        $tools = $toolkit->tools();
        $names = array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools);

        $texts = [(string) $toolkit->guidelines()];
        foreach ($tools as $tool) {
            $texts[] = (string) $tool->getDescription();
        }

        foreach ($texts as $text) {
            preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $text, $matches);
            foreach ($matches[0] as $reference) {
                $this->assertContains($reference, $names, "Text references unknown tool '{$reference}': {$text}");
            }
        }
    }
}
