<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use ArrayObject;
use NeuronAI\StructuredOutput\StructuredOutputException;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\IPAddress;
use NeuronAI\StructuredOutput\Validation\Rules\IsFalse;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsTrue;
use NeuronAI\StructuredOutput\Validation\Rules\Json;
use NeuronAI\StructuredOutput\Validation\Rules\Length;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThan;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\NotEqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\OutOfRange;
use NeuronAI\StructuredOutput\Validation\Rules\Regex;
use NeuronAI\StructuredOutput\Validation\Rules\Url;
use NeuronAI\StructuredOutput\Validation\Rules\WordsCount;
use NeuronAI\StructuredOutput\Validation\ValidationRuleInterface;
use NeuronAI\Tests\StructuredOutput\Stub\Address;
use NeuronAI\Tests\StructuredOutput\Stub\DummyEnum;
use NeuronAI\Tests\StructuredOutput\Stub\IntEnum;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use NeuronAI\Tests\StructuredOutput\Stub\TagProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

use function range;

class RulesTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    protected function violationsOf(ValidationRuleInterface $rule, mixed $value): array
    {
        $violations = [];
        $rule->validate('field', $value, $violations);

        return $violations;
    }

    protected static function stringable(string $value): Stringable
    {
        return new class ($value) implements Stringable {
            public function __construct(protected string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }
        };
    }

    protected static function validTagProperties(): TagProperties
    {
        $properties = new TagProperties();
        $properties->value = 'ok';

        return $properties;
    }

    protected static function validAddress(): Address
    {
        $address = new Address();
        $address->street = 'Via Roma';
        $address->zip = '00100';

        return $address;
    }

    /**
     * @return array<string, array{ValidationRuleInterface, mixed}>
     */
    public static function acceptedValuesProvider(): array
    {
        return [
            'NotBlank text' => [new NotBlank(), 'a'],
            'NotBlank string zero' => [new NotBlank(), '0'],
            'NotBlank integer zero' => [new NotBlank(), 0],
            'NotBlank float zero' => [new NotBlank(), 0.0],
            'NotBlank true' => [new NotBlank(), true],
            'NotBlank non empty array' => [new NotBlank(), [0]],
            'NotBlank null when allowed' => [new NotBlank(allowNull: true), null],

            'IsTrue true' => [new IsTrue(), true],
            'IsFalse false' => [new IsFalse(), false],
            'IsNull null' => [new IsNull(), null],
            'IsNotNull empty string' => [new IsNotNull(), ''],
            'IsNotNull false' => [new IsNotNull(), false],

            'Email simple' => [new Email(), 'info@email.com'],
            'Email with plus and subdomain' => [new Email(), 'first.last+tag@mail.example.co'],
            'Url https' => [new Url(), 'https://inspector.dev'],
            'Url with port path and query' => [new Url(), 'http://localhost:8080/path?q=1#top'],
            'IPAddress v4' => [new IPAddress(), '127.0.0.1'],
            'IPAddress v6' => [new IPAddress(), '2001:db8::1'],

            'Json null is skipped' => [new Json(), null],
            'Json empty string is skipped' => [new Json(), ''],
            'Json object' => [new Json(), '{"a":[1,2]}'],
            'Json list' => [new Json(), '[1]'],
            'Json scalar string' => [new Json(), '"x"'],
            'Json integer value' => [new Json(), 5],
            'Json stringable' => [new Json(), self::stringable('{"a":1}')],

            'Regex match' => [new Regex('/^[a-z]+$/'), 'abc'],
            'Regex unicode match' => [new Regex('/^\p{L}+$/u'), 'Zürich'],

            'Length exactly' => [new Length(exactly: 3), 'abc'],
            'Length counts multibyte characters' => [new Length(max: 3), 'ééé'],
            'Length counts emoji as one character' => [new Length(exactly: 2), '🚀🚀'],
            'Length min boundary' => [new Length(min: 2), 'ab'],
            'Length max boundary' => [new Length(max: 2), 'ab'],
            'Length exactly zero on empty string' => [new Length(exactly: 0), ''],
            'Length stringable' => [new Length(min: 3, max: 3), self::stringable('abc')],

            'Count exactly' => [new Count(exactly: 2), [1, 2]],
            'Count min boundary' => [new Count(min: 2), [1, 2]],
            'Count max boundary' => [new Count(max: 2), [1, 2]],
            'Count countable object' => [new Count(max: 2), new ArrayObject([1, 2])],
            'Count min zero on empty array' => [new Count(min: 0), []],

            'WordsCount exactly' => [new WordsCount(exactly: 3), 'hello world test'],
            'WordsCount ignores repeated separators' => [new WordsCount(exactly: 2), "  hello \r\n  world  "],
            'WordsCount min boundary' => [new WordsCount(min: 2), 'hello world'],
            'WordsCount max boundary' => [new WordsCount(max: 2), 'hello world'],
            'WordsCount stringable' => [new WordsCount(exactly: 1), self::stringable('hello')],
            'WordsCount splits hyphenated words' => [new WordsCount(exactly: 2), 'well-known'],

            'Enum listed value' => [new Enum(values: ['one', 'two']), 'two'],
            'Enum null when nullable' => [new Enum(values: ['one'], nullable: true), null],
            'Enum backed case value' => [new Enum(class: StringEnum::class), 'three'],
            'Enum backed case instance' => [new Enum(class: StringEnum::class), StringEnum::TWO],
            'Enum int backed value' => [new Enum(class: IntEnum::class), 2],

            'EqualTo same value' => [new EqualTo('test'), 'test'],
            'NotEqualTo different value' => [new NotEqualTo('test'), 'other'],
            'NotEqualTo different type' => [new NotEqualTo(5), '5'],
            'GreaterThan above' => [new GreaterThan(30), 31],
            'GreaterThan float above' => [new GreaterThan(0.5), 0.51],
            'GreaterThanEqual boundary' => [new GreaterThanEqual(30), 30],
            'LowerThan below' => [new LowerThan(30), 29],
            'LowerThan negative' => [new LowerThan(0), -1],
            'LowerThanEqual boundary' => [new LowerThanEqual(30), 30],

            'OutOfRange inside' => [new OutOfRange(1, 10), 5],
            'OutOfRange min boundary' => [new OutOfRange(1, 10), 1],
            'OutOfRange max boundary' => [new OutOfRange(1, 10), 10],
            'OutOfRange float inside' => [new OutOfRange(0.5, 1.5), 1.0],
            'OutOfRange strict inside' => [new OutOfRange(1, 10, strict: true), 2],

            'ArrayOf strings' => [new ArrayOf('string'), ['a', 'b']],
            'ArrayOf type name is case insensitive' => [new ArrayOf('STRING'), ['a']],
            'ArrayOf integers' => [new ArrayOf('integer'), [1, -2]],
            'ArrayOf floats' => [new ArrayOf('float'), [1.5]],
            'ArrayOf numeric' => [new ArrayOf('numeric'), [1, '2.5', 3.0]],
            'ArrayOf booleans' => [new ArrayOf('boolean'), [true, false]],
            'ArrayOf scalars' => [new ArrayOf('scalar'), [1, 'a', true]],
            'ArrayOf arrays' => [new ArrayOf('array'), [[1], []]],
            'ArrayOf nulls' => [new ArrayOf('null'), [null]],
            'ArrayOf objects' => [new ArrayOf('object'), [new stdClass()]],
            'ArrayOf countable' => [new ArrayOf('countable'), [[], new ArrayObject()]],
            'ArrayOf digits' => [new ArrayOf('digit'), ['123', '0']],
            'ArrayOf lowercase' => [new ArrayOf('lower'), ['abc']],
            'ArrayOf empty allowed' => [new ArrayOf('string', allowEmpty: true), []],
            'ArrayOf null allowed as empty' => [new ArrayOf('string', allowEmpty: true), null],
            'ArrayOf valid objects of listed class' => [new ArrayOf(TagProperties::class), [self::validTagProperties()]],
            'ArrayOf objects of any listed class' => [new ArrayOf([Address::class, TagProperties::class]), [self::validTagProperties(), self::validAddress()]],
        ];
    }

    #[DataProvider('acceptedValuesProvider')]
    public function test_accepts_valid_values(ValidationRuleInterface $rule, mixed $value): void
    {
        $this->assertSame([], $this->violationsOf($rule, $value));
    }

    /**
     * @return array<string, array{ValidationRuleInterface, mixed, array<int, string>}>
     */
    public static function rejectedValuesWithMessageProvider(): array
    {
        return [
            'NotBlank empty string' => [new NotBlank(), '', ['field cannot be blank']],
            'NotBlank null' => [new NotBlank(), null, ['field cannot be blank']],
            'NotBlank empty array' => [new NotBlank(), [], ['field cannot be blank']],
            'NotBlank false' => [new NotBlank(), false, ['field cannot be blank']],
            'NotBlank allowNull still rejects empty string' => [new NotBlank(allowNull: true), '', ['field cannot be blank']],

            'IsTrue rejects truthy integer' => [new IsTrue(), 1, ['field must be true']],
            'IsTrue rejects string true' => [new IsTrue(), 'true', ['field must be true']],
            'IsFalse rejects zero' => [new IsFalse(), 0, ['field must be false']],
            'IsFalse rejects null' => [new IsFalse(), null, ['field must be false']],
            'IsNull rejects empty string' => [new IsNull(), '', ['field must be null']],
            'IsNotNull rejects null' => [new IsNotNull(), null, ['field must not be null']],

            'Email without domain' => [new Email(), 'test', ['field must be a valid email address']],
            'Email with trailing newline' => [new Email(), "a@b.co\n", ['field must be a valid email address']],
            'Email with markup' => [new Email(), 'a@b.co<script>', ['field must be a valid email address']],
            'Email null' => [new Email(), null, ['field must be a valid email address']],
            'Email array' => [new Email(), ['a@b.co'], ['field must be a valid email address']],

            'Url without scheme' => [new Url(), 'inspector.dev', ['field must be a valid URL']],
            'Url with header injection' => [new Url(), "https://inspector.dev/\r\nX-Injected: 1", ['field must be a valid URL']],
            'Url with spaces' => [new Url(), 'https://inspector .dev', ['field must be a valid URL']],
            'Url javascript without authority' => [new Url(), 'javascript:alert(1)', ['field must be a valid URL']],
            'Url empty' => [new Url(), '', ['field must be a valid URL']],

            'IPAddress incomplete' => [new IPAddress(), '127.0.0', ['field must be a valid IP address']],
            'IPAddress octet overflow' => [new IPAddress(), '256.1.1.1', ['field must be a valid IP address']],
            'IPAddress with whitespace' => [new IPAddress(), ' 127.0.0.1', ['field must be a valid IP address']],
            'IPAddress integer' => [new IPAddress(), 127, ['field must be a valid IP address']],

            'Json invalid' => [new Json(), 'invalid json', ['field must be a valid JSON string']],
            'Json unquoted keys' => [new Json(), '{a:1}', ['field must be a valid JSON string']],
            'Json single quotes' => [new Json(), "{'a':1}", ['field must be a valid JSON string']],
            'Json truncated' => [new Json(), '{"a":', ['field must be a valid JSON string']],

            'Regex mismatch' => [new Regex('/^[a-z]+$/'), 'abc1', ['field must match the pattern /^[a-z]+$/']],
            'Regex non string' => [new Regex('/^\d+$/'), 123, ['field must match the pattern /^\d+$/']],
            'Regex null' => [new Regex('/.*/'), null, ['field must match the pattern /.*/']],
            'Regex malformed utf8 subject' => [new Regex('/^.*$/u'), "\xC3\x28", ['field must match the pattern /^.*$/u']],

            'Length too long' => [new Length(max: 2), 'abc', ['field is too long. It must be at most 2 characters']],
            'Length too short' => [new Length(min: 2), 'a', ['field is too short. It must be at least 2 characters']],
            'Length multibyte too long' => [new Length(max: 3), 'éééé', ['field is too long. It must be at most 3 characters']],
            'Length exactly too short' => [new Length(exactly: 3), 'ab', ['field must be exactly 3 characters long']],
            'Length exactly too long' => [new Length(exactly: 3), 'abcd', ['field must be exactly 3 characters long']],
            'Length range too short' => [new Length(min: 2, max: 4), 'a', ['field is too short. It must be at least 2 characters']],
            'Length range too long' => [new Length(min: 2, max: 4), 'abcde', ['field is too long. It must be at most 4 characters']],
            'Length null with min' => [new Length(min: 1), null, ['field cannot be empty']],
            'Length null with exactly' => [new Length(exactly: 1), null, ['field cannot be empty']],

            'Count too many' => [new Count(max: 1), [1, 2], ['field is too long. It must be at most 1 items']],
            'Count too few' => [new Count(min: 2), [1], ['field is too short. It must be at least 2 items']],
            'Count exactly too few' => [new Count(exactly: 2), [1], ['field must be exactly 2 items long']],
            'Count exactly too many' => [new Count(exactly: 2), [1, 2, 3], ['field must be exactly 2 items long']],
            'Count range too many' => [new Count(min: 2, max: 4), range(1, 5), ['field is too long. It must be at most 4 items']],
            'Count countable too many' => [new Count(max: 1), new ArrayObject([1, 2]), ['field is too long. It must be at most 1 items']],
            'Count null with min' => [new Count(min: 1), null, ['field cannot be empty']],

            'WordsCount too many' => [new WordsCount(max: 2), 'a b c', ['field is too long. It must be at most 2 words']],
            'WordsCount hyphenated words count separately' => [new WordsCount(max: 1), 'well-known', ['field is too long. It must be at most 1 words']],
            'WordsCount too few' => [new WordsCount(min: 2), 'a', ['field is too short. It must be at least 2 words']],
            'WordsCount exactly too many' => [new WordsCount(exactly: 1), 'a b', ['field must have exactly 1 words']],
            'WordsCount null with min' => [new WordsCount(min: 1), null, ['field cannot be empty']],
            'WordsCount non string' => [new WordsCount(max: 1), 5, ['field must be a string or a stringable object']],

            'Enum unlisted value' => [new Enum(values: ['one', 'two']), 'four', ['field must be one of the following allowed values: one, two.']],
            'Enum strict comparison' => [new Enum(values: [1, 2]), '1', ['field must be one of the following allowed values: 1, 2.']],
            'Enum values from backed enum' => [new Enum(class: StringEnum::class), 'ONE', ['field must be one of the following allowed values: one, two, three.']],
            'Enum case of another enum' => [new Enum(class: IntEnum::class), StringEnum::ONE, ['field must be one of the following allowed values: 1, 2, 3.']],

            'ArrayOf empty not allowed' => [new ArrayOf('string'), [], ['field must be an array of string']],
            'ArrayOf null not allowed' => [new ArrayOf('string'), null, ['field must be an array of string']],
            'ArrayOf wrong scalar item' => [new ArrayOf('string'), ['a', 1], ['field must be an array of string']],
            'ArrayOf integer rejects numeric string' => [new ArrayOf('integer'), ['1'], ['field must be an array of integer']],
            'ArrayOf digit rejects mixed' => [new ArrayOf('digit'), ['123', '12a'], ['field must be an array of digit']],
            'ArrayOf unknown type name' => [new ArrayOf('uuid'), ['x'], ['field must be an array of uuid']],
            'ArrayOf object of unlisted class' => [new ArrayOf(TagProperties::class), [new Address()], ['field must be an array of '.TagProperties::class]],
            'ArrayOf invalid nested object' => [new ArrayOf(TagProperties::class), [new TagProperties()], ['field must be an array of '.TagProperties::class]],
            'ArrayOf array instead of object' => [new ArrayOf(TagProperties::class), [['value' => 'x']], ['field must be an array of '.TagProperties::class]],
            'ArrayOf reports once for many bad items' => [new ArrayOf('string'), [1, 2, 3], ['field must be an array of string']],
        ];
    }

    /**
     * @param array<int, string> $expected
     */
    #[DataProvider('rejectedValuesWithMessageProvider')]
    public function test_rejects_invalid_values_with_a_descriptive_message(ValidationRuleInterface $rule, mixed $value, array $expected): void
    {
        $this->assertSame($expected, $this->violationsOf($rule, $value));
    }

    /**
     * Rules whose violation message is not asserted here: see the comparison
     * rules message issue reported in the review notes.
     *
     * @return array<string, array{ValidationRuleInterface, mixed}>
     */
    public static function rejectedValuesProvider(): array
    {
        return [
            'EqualTo different value' => [new EqualTo('test'), 'test2'],
            'EqualTo is strict' => [new EqualTo(5), '5'],
            'EqualTo null' => [new EqualTo(0), null],
            'NotEqualTo same value' => [new NotEqualTo('test'), 'test'],
            'GreaterThan boundary' => [new GreaterThan(30), 30],
            'GreaterThan below' => [new GreaterThan(30), 29],
            'GreaterThan null value' => [new GreaterThan(30), null],
            'GreaterThan null reference' => [new GreaterThan(null), 5],
            'GreaterThanEqual below' => [new GreaterThanEqual(30), 29],
            'GreaterThanEqual float below' => [new GreaterThanEqual(1.5), 1.49],
            'GreaterThanEqual null reference' => [new GreaterThanEqual(null), 5],
            'LowerThan boundary' => [new LowerThan(30), 30],
            'LowerThan above' => [new LowerThan(30), 31],
            'LowerThan null value' => [new LowerThan(30), null],
            'LowerThanEqual above' => [new LowerThanEqual(30), 31],
            'LowerThanEqual null value' => [new LowerThanEqual(30), null],
            'OutOfRange below' => [new OutOfRange(1, 10), 0],
            'OutOfRange above' => [new OutOfRange(1, 10), 11],
            'OutOfRange float above' => [new OutOfRange(0.5, 1.5), 1.51],
            'OutOfRange null below positive min' => [new OutOfRange(1, 10), null],
            'OutOfRange strict min boundary' => [new OutOfRange(1, 10, strict: true), 1],
            'OutOfRange strict max boundary' => [new OutOfRange(1, 10, strict: true), 10],
            'Enum null when not nullable' => [new Enum(values: ['one']), null],
            'ArrayOf non array value' => [new ArrayOf('string'), 'abc'],
            'Length integer value' => [new Length(max: 3), 12],
            'Length array value' => [new Length(max: 3), ['a']],
            'WordsCount exactly too few' => [new WordsCount(exactly: 3), 'a b'],
        ];
    }

    #[DataProvider('rejectedValuesProvider')]
    public function test_rejects_invalid_values(ValidationRuleInterface $rule, mixed $value): void
    {
        $this->assertCount(1, $this->violationsOf($rule, $value));
    }

    public function test_violations_are_appended_to_existing_ones(): void
    {
        $violations = ['previous violation'];

        (new IsTrue())->validate('first', false, $violations);
        (new NotBlank())->validate('second', '', $violations);

        $this->assertSame(['previous violation', 'first must be true', 'second cannot be blank'], $violations);
    }

    /**
     * @return array<string, array{callable(): ValidationRuleInterface, mixed, string}>
     */
    public static function misconfiguredRuleProvider(): array
    {
        return [
            'Length without bounds' => [fn (): Length => new Length(), 'abc', 'Either option "min" or "max" must be given for validation rule "Length"'],
            'Count without bounds' => [fn (): Count => new Count(), [], 'Either option "min" or "max" must be given for validation rule'],
            'WordsCount without bounds' => [fn (): WordsCount => new WordsCount(), 'abc', 'Either option "min" or "max" must be given for validation rule "WordsCount"'],
            'Count on non countable value' => [fn (): Count => new Count(max: 2), 'abc', 'field must be an array or a Countable object'],
            'Json on array value' => [fn (): Json => new Json(), ['a'], 'Cannot validate a non-scalar value.'],
            'Json on plain object' => [fn (): Json => new Json(), new stdClass(), 'Cannot validate a non-scalar value.'],
            'Enum with values and class' => [fn (): Enum => new Enum(values: ['one'], class: StringEnum::class), 'one', 'You cannot provide both "values" and "class" options simultaneously. Please use only one.'],
            'Enum without values or class' => [fn (): Enum => new Enum(), 'one', 'Either option "values" or "class" must be given for validation rule "Enum"'],
            'Enum with null values and no class' => [fn (): Enum => new Enum(values: null), 'one', 'Either option "values" or "class" must be given for validation rule "Enum"'],
            'Enum with a non enum class' => [fn (): Enum => new Enum(class: Address::class), 'one', "Enum '".Address::class."' does not exist."],
            'Enum with a pure enum' => [fn (): Enum => new Enum(class: DummyEnum::class), 'A', "Enum '".DummyEnum::class."' must implement BackedEnum."],
        ];
    }

    /**
     * @param callable(): ValidationRuleInterface $ruleFactory
     */
    #[DataProvider('misconfiguredRuleProvider')]
    public function test_misconfigured_rules_or_unsupported_values_throw(callable $ruleFactory, mixed $value, string $message): void
    {
        $this->expectException(StructuredOutputException::class);
        $this->expectExceptionMessage($message);

        $this->violationsOf($ruleFactory(), $value);
    }
}
