<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\Validation\Validator;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ArrayOf extends AbstractValidationRule
{
    protected string $message = '{name} must be an array of {types}';

    protected array $types;

    private const VALIDATION_FUNCTIONS = [
        'boolean' => 'is_bool',
        'integer' => 'is_int',
        'float' => 'is_float',
        'numeric' => 'is_numeric',
        'string' => 'is_string',
        'scalar' => 'is_scalar',
        'array' => 'is_array',
        'iterable' => 'is_iterable',
        'countable' => 'is_countable',
        'object' => 'is_object',
        'null' => 'is_null',
        'alnum' => 'ctype_alnum',
        'alpha' => 'ctype_alpha',
        'cntrl' => 'ctype_cntrl',
        'digit' => 'ctype_digit',
        'graph' => 'ctype_graph',
        'lower' => 'ctype_lower',
        'print' => 'ctype_print',
        'punct' => 'ctype_punct',
        'space' => 'ctype_space',
        'upper' => 'ctype_upper',
        'xdigit' => 'ctype_xdigit',
    ];

    public function __construct(
        string|array $type,
        protected bool $allowEmpty = false,
    ) {
        $this->types = \is_array($type) ? $type : [$type];
    }

    /**
     * @throws \ReflectionException
     */
    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($this->allowEmpty && empty($value)) {
            return;
        }

        if (!$this->allowEmpty && empty($value)) {
            $violations[] = $this->buildMessage($name, $this->message, ['types' => \implode(', ', $this->types)]);
            return;
        }

        if (!\is_array($value)) {
            $violations[] = $this->buildMessage($name, $this->message);
            return;
        }

        $error = false;
        foreach ($value as $item) {
            foreach ($this->types as $type) {
                // Check scalar types.
                if (isset(self::VALIDATION_FUNCTIONS[\strtolower((string) $type)]) && self::VALIDATION_FUNCTIONS[\strtolower((string) $type)]($item)) {
                    continue 2;
                }

                // Check object types.
                // It's like a recursive call.
                if (\is_object($item) && \in_array($item::class, $this->types) && Validator::validate($item) === []) {
                    continue 2;
                }

                $error = true;
                break;
            }
        }

        if ($error) {
            $violations[] = $this->buildMessage($name, $this->message, ['types' => \implode(', ', $this->types)]);
        }
    }
}
