<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Category
{
    #[NotBlank]
    public string $name;

    public ?Category $parent = null;

    /**
     * @var Category[]
     */
    #[ArrayOf(Category::class, allowEmpty: true)]
    public array $children = [];
}
