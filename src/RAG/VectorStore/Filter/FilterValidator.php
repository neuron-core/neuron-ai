<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;

use function get_debug_type;
use function in_array;

final class FilterValidator
{
    public function validate(FilterExpression $filters, DocumentSchema $schema): void
    {
        if ($filters instanceof FilterGroup) {
            foreach ($filters->conditions() as $condition) {
                $this->validate($condition, $schema);
            }
            return;
        }

        if ($filters instanceof RawFilter) {
            return;
        }

        if (!$filters instanceof Filter) {
            throw new VectorStoreException('Unsupported filter expression: ' . $filters::class . '.');
        }

        $field = $schema->requireFilterableField($filters->field);
        $type = $field->getType();

        if ($filters->operator === FilterOperator::Neq && !$field->isRequired()) {
            throw new DocumentSchemaException(
                "Portable neq filters require field \"{$filters->field}\" to be declared required, so missing fields cannot match differently across databases."
            );
        }

        $contains = in_array(
            $filters->operator,
            [FilterOperator::ContainsAny, FilterOperator::ContainsAll],
            true,
        );

        if ($contains && $type !== \NeuronAI\RAG\Schema\DocumentFieldType::StringArray) {
            throw new DocumentSchemaException(
                "Filter operator {$filters->operator->value} requires a filterable string[] field; \"{$filters->field}\" is {$type->value}."
            );
        }

        if (!$contains && $type->isArray()) {
            throw new DocumentSchemaException(
                "Array field \"{$filters->field}\" requires contains_any or contains_all."
            );
        }

        if (in_array($filters->operator, [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte], true)
            && !$type->isNumeric()) {
            throw new DocumentSchemaException(
                "Filter operator {$filters->operator->value} requires a numeric field; \"{$filters->field}\" is {$type->value}."
            );
        }

        $values = in_array(
            $filters->operator,
            [FilterOperator::In, FilterOperator::ContainsAny, FilterOperator::ContainsAll],
            true,
        ) ? $filters->value : [$filters->value];

        foreach ((array) $values as $value) {
            $valueType = $contains ? $type->elementType() : $type;

            if (!$schema->valueMatches($valueType, $value)) {
                throw new DocumentSchemaException(
                    "Filter field \"{$filters->field}\" expects {$valueType->value}; ".get_debug_type($value).' given.'
                );
            }
        }
    }
}
