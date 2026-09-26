<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

use NeuronAI\StructuredOutput\SchemaProperty;

class TreeNode
{
    public string $name;

    public ?TreeNode $parent = null;

    /**
     * @var TreeNode[]
     */
    #[SchemaProperty(anyOf: [TreeNode::class])]
    public array $children = [];
}
