<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

use function strtolower;
use function str_replace;

class Article
{
    public string $slug;

    public function __construct(
        public string $title = '',
        public int $version = 1,
    ) {
        $this->slug = strtolower(str_replace(' ', '-', $this->title));
    }
}
