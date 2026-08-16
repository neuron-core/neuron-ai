<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;

use function get_debug_type;
use function in_array;

final class FilterValidator
{
    public function validate(FilterGroup $filters, DocumentSchema $schema): void
    {
        foreach ($filters->conditions() as $condition) {
            if ($condition instanceof RawFilter) {
                continue;
            }

            $field = $schema->requireFilterableField($condition->field);
            $type = $field->getType();

            if ($condition->operator === FilterOperator::Neq && !$field->isRequired()) {
                throw new DocumentSchemaException(
                    "Portable neq filters require field \"{$condition->field}\" to be declared required, so missing fields cannot match differently across databases."
                );
            }

            if ($type->isArray()) {
                throw new DocumentSchemaException(
                    "Portable filters for array field \"{$condition->field}\" are not supported yet. Use a raw backend filter."
                );
            }

            if (in_array($condition->operator, [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte], true)
                && !$type->isNumeric()) {
                throw new DocumentSchemaException(
                    "Filter operator {$condition->operator->value} requires a numeric field; \"{$condition->field}\" is {$type->value}."
                );
            }

            $values = $condition->operator === FilterOperator::In ? $condition->value : [$condition->value];

            foreach ((array) $values as $value) {
                if (!$schema->valueMatches($type, $value)) {
                    throw new DocumentSchemaException(
                        "Filter field \"{$condition->field}\" expects {$type->value}; ".get_debug_type($value).' given.'
                    );
                }
            }
        }
    }
}
