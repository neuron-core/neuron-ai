<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use NeuronAI\Tools\Toolkits\Zep\ZepAddToGraphTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

class ZepAddToGraphToolSchemaTest extends TestCase
{
    public function test_property_descriptions_describe_an_insert_not_a_search(): void
    {
        $properties = [];
        foreach (ZepAddToGraphTool::make('key', 'user')->getProperties() as $property) {
            /** @var ToolPropertyInterface $property */
            $properties[$property->getName()] = $property;
        }

        $this->assertSame(['text', 'json', 'message'], $properties['type']->getEnum());

        foreach ($properties as $property) {
            $this->assertStringNotContainsStringIgnoringCase('search', (string) $property->getDescription());
        }

        foreach (['text', 'json', 'message'] as $allowedType) {
            $this->assertStringContainsString($allowedType, (string) $properties['type']->getDescription());
        }
    }
}
