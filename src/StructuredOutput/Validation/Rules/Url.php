<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput\Validation\Rules;

use Attribute;

use function array_map;
use function filter_var;
use function implode;
use function in_array;
use function is_string;
use function parse_url;
use function strtolower;

use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Url extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid URL ({schemes})';

    /**
     * @param string[] $schemes The schemes a URL may use: the default keeps out javascript, file, gopher or data URLs
     */
    public function __construct(protected array $schemes = ['http', 'https'])
    {
    }

    public function validate(string $name, mixed $value, array &$violations): void
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !$this->hasAcceptedScheme((string) $value)) {
            $violations[] = $this->buildMessage($name, $this->message, ['schemes' => implode(', ', $this->schemes)]);
        }
    }

    protected function hasAcceptedScheme(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), array_map(strtolower(...), $this->schemes), true);
    }
}
