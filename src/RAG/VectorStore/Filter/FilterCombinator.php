<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

enum FilterCombinator: string
{
    case And = 'and';
    case Or = 'or';
}
