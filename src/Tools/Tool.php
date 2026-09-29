<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use Closure;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

use function array_key_exists;
use function array_reduce;
use function is_array;
use function json_encode;
use function method_exists;
use function sprintf;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

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

    /**
     * Raw provider-specific keys the ToolMapper merges verbatim into the tool
     * definition sent to the LLM, e.g. a hand-written schema or cache_control.
     */
    protected array $parameters = [];

    protected array $annotations = [];

    protected array $inputs = [];

    /**
     * Why the bound inputs were rejected by their properties' cast(); null when they are valid.
     */
    protected ?string $invalidInput = null;

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

    /** @return array<string, mixed> */
    public function getInputSchema(): array
    {
        $properties = [];
        foreach ($this->getProperties() as $property) {
            $properties[$property->getName()] = $property->getJsonSchema();
        }

        return [
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass() : $properties,
            'required' => $this->getRequiredProperties(),
        ];
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

    /**
     * Binding is casting: the approval policy, the run key and __invoke() must all
     * judge the same typed values, never the model's raw spelling of them.
     *
     * @throws ReflectionException
     */
    public function setInputs(?array $inputs): self
    {
        $this->inputs = $inputs ?? [];
        $this->invalidInput = null;

        $cast = $this->inputs;

        foreach ($this->getProperties() as $property) {
            $name = $property->getName();

            try {
                if (!array_key_exists($name, $cast)) {
                    if ($property->isRequired()) {
                        throw new InvalidToolInput('is required');
                    }

                    continue;
                }

                if ($cast[$name] === null && $property->isRequired() && !$property->isNullable()) {
                    throw new InvalidToolInput("must be of type {$property->getType()->value}, null given");
                }

                $cast[$name] = $property->cast($cast[$name]);
            } catch (InvalidToolInput $exception) {
                // A value the model left out or sent in the wrong type is feedback to correct the call, not a bug
                $this->invalidInput = "Parameter \"{$name}\" {$exception->getMessage()}.";
                return $this;
            }
        }

        $this->inputs = $cast;
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

    /**
     * @throws ToolException When the tool has no result — check hasResult() first.
     */
    public function getResult(): string|ToolOutput
    {
        if ($this->result === null) {
            throw new ToolException("Tool {$this->name} has no result: it was never executed.");
        }

        return $this->result;
    }

    public function setResult(mixed $result): ToolInterface
    {
        if ($result instanceof ToolOutput) {
            $this->result = $result;
        } else {
            $this->result = is_array($result) ? json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) : (string) $result;
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
    public function requiresApproval(): bool|string
    {
        // Rejected inputs never reach __invoke(): there is nothing to approve
        if ($this->invalidInput !== null) {
            return false;
        }

        if ($this->approvalPolicyOverride instanceof Closure) {
            return ($this->approvalPolicyOverride)($this);
        }

        if ($this->approvalRequired !== null) {
            return $this->approvalRequired;
        }

        return $this->approvalPolicy();
    }

    /**
     * The tool author's intrinsic approval declaration: override in a subclass to
     * declare the tool's own risk. A string counts as true AND carries the reason
     * shown to the approver. Attach-time overrides beat this in both directions.
     */
    protected function approvalPolicy(): bool|string
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
     */
    public function execute(): void
    {
        if (!method_exists($this, '__invoke')) {
            throw new ToolCallableNotSet(sprintf(
                'Tool "%s" must implement __invoke() to define its execution logic.',
                $this->name,
            ));
        }

        if ($this->invalidInput !== null) {
            $this->setResult(ToolOutput::error($this->invalidInput));
            return;
        }

        // Reached only by a tool executed without setInputs(), which settles a missing input as feedback
        foreach ($this->getProperties() as $property) {
            if ($property->isRequired() && !array_key_exists($property->getName(), $this->getInputs())) {
                throw new MissingCallbackParameter("Missing required parameter: {$property->getName()}");
            }
        }

        $signature = $this->invokeParameters();
        $parameters = [];

        foreach ($this->getProperties() as $property) {
            $name = $property->getName();
            $parameter = $signature[$name] ?? null;

            // Left out, or null where the parameter can't take it: PHP applies the declared default
            if (
                $parameter?->isDefaultValueAvailable()
                && (!array_key_exists($name, $this->inputs) || ($this->inputs[$name] === null && !$parameter->allowsNull()))
            ) {
                continue;
            }

            $parameters[$name] = $this->inputs[$name] ?? null;
        }

        $this->setResult($this->__invoke(...$parameters));
    }

    /**
     * @return array<string, ReflectionParameter>
     */
    protected function invokeParameters(): array
    {
        $parameters = [];
        foreach ((new ReflectionMethod($this, '__invoke'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
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
