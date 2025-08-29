<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\Validation\Validator;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ArrayOf extends AbstractValidationRule
{
    protected string $message = '{name} must be an array of {type}';

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
        protected string $type,
        protected bool $allowEmpty = false,
    ) {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if ($this->allowEmpty && empty($value)) {
            return;
        }

        if (!$this->allowEmpty && empty($value)) {
            $violations[] = $this->buildMessage($name, $this->message, ['type' => $this->type]);
            return;
        }

        if (!\is_array($value)) {
            $violations[] = $this->buildMessage($name, $this->message);
            return;
        }

        $type = \strtolower($this->type);

        $error = false;
        foreach ($value as $item) {
            if (isset(self::VALIDATION_FUNCTIONS[$type]) && self::VALIDATION_FUNCTIONS[$type]($item)) {
                continue;
            }

            // It's like a recursive call.
            if ($item instanceof $this->type && Validator::validate($item) === []) {
                continue;
            }

            $error = true;
            break;
        }

        if ($error) {
            $violations[] = $this->buildMessage($name, $this->message, ['type' => $this->type]);
        }
    }
}
