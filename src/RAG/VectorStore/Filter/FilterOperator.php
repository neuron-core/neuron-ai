<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

enum FilterOperator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case In = 'in';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case ContainsAny = 'contains_any';
    case ContainsAll = 'contains_all';
}
