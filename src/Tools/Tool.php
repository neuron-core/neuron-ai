<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use Closure;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\StaticConstructor;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use ReflectionException;
use stdClass;

use function array_key_exists;
use function array_map;
use function array_reduce;
use function is_array;
use function json_encode;
use function method_exists;
use function sprintf;

/**
 * @method static static make(...$arguments)
 */
abstract class Tool implements ToolInterface
{
    use StaticConstructor;

    protected string $name;

    protected ?string $description = null;

    /**
     * @var ToolPropertyInterface[]
     */
    protected array $properties = [];

    protected array $parameters = [];

    protected array $annotations = [];

    protected array $inputs = [];

    protected ?string $callId = null;

    protected string|ToolOutput|null $result = null;

    /**
     * Attach-time flat override, beating the class's own approvalPolicy().
     * Set via requireApproval() / suppressApproval(); null = no override.
     */
    protected ?bool $approvalRequired = null;

    /**
     * Attach-time policy override replacing the class's own approvalPolicy().
     * Set via withApprovalPolicy().
     *
     * @var (Closure(ToolInterface): (bool|string))|null
     */
    protected ?Closure $approvalPolicyOverride = null;

    protected ?int $maxRuns = null;

    protected bool $visible = true;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): ToolInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): ToolInterface
    {
        $this->description = $description;
        return $this;
    }

    public function addProperty(ToolPropertyInterface $property): ToolInterface
    {
        $this->properties[] = $property;
        return $this;
    }

    /**
     * @return ToolPropertyInterface[]
     */
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return ToolPropertyInterface[]
     */
    public function getProperties(): array
    {
        if ($this->properties === []) {
            foreach ($this->properties() as $property) {
                $this->addProperty($property);
            }
        }

        return $this->properties;
    }

    public function getRequiredProperties(): array
    {
        return array_reduce($this->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
            if ($property->isRequired()) {
                $carry[] = $property->getName();
            }

            return $carry;
        }, []);
    }

    public function getAnnotations(): array
    {
        return $this->annotations;
    }

    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getInputs(): array
    {
        return $this->inputs;
    }

    public function getInput(string $key): mixed
    {
        return $this->inputs[$key] ?? null;
    }

    public function setInputs(?array $inputs): self
    {
        $this->inputs = $inputs ?? [];
        return $this;
    }

    public function getCallId(): ?string
    {
        return $this->callId;
    }

    public function setCallId(?string $callId): self
    {
        $this->callId = $callId;
        return $this;
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function getResult(): string|ToolOutput
    {
        return $this->result;
    }

    public function setResult(mixed $result): ToolInterface
    {
        if ($result instanceof ToolOutput) {
            $this->result = $result;
        } else {
            $this->result = is_array($result) ? json_encode($result) : (string) $result;
        }

        return $this;
    }

    public function getMaxRuns(): ?int
    {
        return $this->maxRuns;
    }

    public function setMaxRuns(int $tries): self
    {
        $this->maxRuns = $tries;
        return $this;
    }

    public function visible(bool $visible): ToolInterface
    {
        $this->visible = $visible;
        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function getRunKey(): string
    {
        return $this->getName();
    }

    /**
     * Resolution order: attach-time policy callback → attach-time flat override →
     * the class's own approvalPolicy(). A string counts as true and doubles as the
     * approval reason shown to the approver.
     */
    public function requiresApproval(array $inputs): bool|string
    {
        if ($this->approvalPolicyOverride instanceof Closure) {
            return ($this->approvalPolicyOverride)($this);
        }

        if ($this->approvalRequired !== null) {
            return $this->approvalRequired;
        }

        return $this->approvalPolicy($inputs);
    }

    /**
     * The tool author's intrinsic approval declaration: override in a subclass to
     * declare the tool's own risk. A string counts as true AND carries the reason
     * shown to the approver. Attach-time overrides beat this in both directions.
     */
    protected function approvalPolicy(array $inputs): bool|string
    {
        return false;
    }

    /**
     * Force the approval gate's answer, beating the tool's own approvalPolicy().
     * Clears any withApprovalPolicy() callback — the last configured override wins.
     */
    public function requireApproval(bool $require = true): ToolInterface
    {
        $this->approvalRequired = $require;
        $this->approvalPolicyOverride = null;
        return $this;
    }

    /**
     * Waive this tool's declared approval requirement. Sugar for requireApproval(false).
     */
    public function suppressApproval(): ToolInterface
    {
        return $this->requireApproval(false);
    }

    /**
     * Replace the tool's own approvalPolicy(); a string return counts as true and
     * doubles as the approval reason. Clears any requireApproval() flat override —
     * the last configured override wins.
     *
     * @param callable(ToolInterface): (bool|string) $policy
     */
    public function withApprovalPolicy(callable $policy): ToolInterface
    {
        $this->approvalPolicyOverride = $policy(...);
        $this->approvalRequired = null;
        return $this;
    }

    /**
     * @throws MissingCallbackParameter
     * @throws ToolCallableNotSet
     * @throws DeserializerException
     * @throws ReflectionException
     */
    public function execute(): void
    {
        if (!method_exists($this, '__invoke')) {
            throw new ToolCallableNotSet(sprintf(
                'Tool "%s" must implement __invoke() to define its execution logic.',
                $this->name,
            ));
        }

        foreach ($this->getProperties() as $property) {
            if ($property->isRequired() && !array_key_exists($property->getName(), $this->getInputs())) {
                throw new MissingCallbackParameter("Missing required parameter: {$property->getName()}");
            }
        }

        $parameters = array_reduce($this->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
            $propertyName = $property->getName();
            $inputs = $this->getInputs();

            // Missing optional properties become explicit nulls for a consistent structure
            if (!array_key_exists($propertyName, $inputs)) {
                $carry[$propertyName] = null;
                return $carry;
            }

            $inputValue = $inputs[$propertyName];

            if ($property instanceof ObjectProperty && $property->getClass()) {
                $carry[$propertyName] = Deserializer::make()->fromJson(json_encode($inputValue), $property->getClass());
                return $carry;
            }

            if ($property instanceof ArrayProperty) {
                $items = $property->getItems();
                if ($items instanceof ObjectProperty && $items->getClass()) {
                    $class = $items->getClass();
                    $carry[$propertyName] = array_map(fn (array|object $input): object => Deserializer::make()->fromJson(json_encode($input), $class), $inputValue);
                    return $carry;
                }
            }

            $carry[$propertyName] = $inputValue;
            return $carry;

        }, []);

        $this->setResult($this->__invoke(...$parameters));
    }

    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'inputs' => $this->inputs === [] ? new stdClass() : $this->inputs,
            'result' => $this->result instanceof ToolOutput ? $this->result->jsonSerialize() : $this->result,
        ];
    }
}
