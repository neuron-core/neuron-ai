# Upgrade: The evaluation command builds classes through a resolver; make:* commands merged

## Summary

The `vendor/bin/neuron` commands keep their names, arguments and options. The `output` entry of `evaluation.php` is
also unchanged. This guide applies to PHP code that constructs or extends the console command classes or their
collaborators. Case 6 applies to every application that runs `neuron evaluation`.

- `EvaluationCommand` no longer accepts an `EvaluationOutputResolver`. It builds evaluators and class-string output
  drivers with a resolver, a `callable(class-string): object`. You pass it as `resolver:` or set it as the `resolver`
  entry of `evaluation.php`. With no resolver, the command still calls `new $class()` as in 3.x, and still refuses
  classes whose constructor has required parameters.
- `EvaluationOutputResolver` takes that resolver as a required `Closure` constructor argument.
- The private methods of `EvaluationCommand` are now protected, and the command extends the new abstract
  `NeuronAI\Console\Command`. A subclass that declares a member with the same name no longer loads.
- `EvaluatorDiscovery` moved to another namespace. It now also finds evaluators that 3.x skipped (`final class`,
  indented declarations) and returns them sorted by class name.
- The eight `Make*Command` classes are removed. One concrete `MakeCommand`, configured through its constructor,
  replaces them.

| 3.x | 4.x |
|---|---|
| `EvaluationCommand::__construct(?ConfigLoader $configLoader = new ConfigLoader(), ?EvaluationOutputResolver $driverResolver = new EvaluationOutputResolver())` | `EvaluationCommand::__construct(ConfigLoader $configLoader = new ConfigLoader(), EvaluatorDiscovery $discovery = new EvaluatorDiscovery(), ?EvaluatorRunner $runner = null, ?callable $resolver = null)` |
| `new EvaluationOutputResolver()` | `new EvaluationOutputResolver(Closure $resolver)` |
| `EvaluationOutputResolver` subclass overriding `resolveOne()`, passed as `driverResolver` | a resolver closure passed as `resolver:` |
| private `createEvaluator(string $className): BaseEvaluator` and the other private helpers of `EvaluationCommand` | protected, with the signatures in Case 4 |
| `NeuronAI\Evaluation\Discovery\EvaluatorDiscovery` | `NeuronAI\Evaluation\EvaluatorDiscovery` |
| `new MakeAgentCommand()` and the seven other `Make*Command` classes | `new MakeCommand('make:agent', 'Agent', 'agent.stub')` |
| abstract `MakeCommand::__construct(string $resourceType)` | `MakeCommand::__construct(string $commandName, string $resourceType, string $stubFile)` |

No stored data is involved. 4.x reads 3.x `evaluation.php` files as they are. It also reads three new optional keys
from them: `resolver` (must be callable), `runner` (must be an `EvaluatorRunner`) and `cache`. If the file already
uses one of these keys for something else, rename that key.

The `discovery` and `runner` constructor parameters, the `runner` and `cache` entries and the `--cache`/`--fresh`
options are new and optional. A 3.x application has nothing to migrate for them.

Guide 54 migrates custom output drivers, `EvaluatorSummary`, and scripts that read the console or JSON output. That
includes the error lines, which now go to STDERR. Guide 53 migrates `EvaluatorRunner` subclasses.

## What to Search For

Run from the application root:

```bash
# 1. The evaluation command and its collaborators (Cases 1-5, 7)
grep -rnE 'EvaluationCommand|EvaluationOutputResolver|driverResolver|EvaluatorDiscovery|NeuronAI\\+Evaluation\\+Config\\+ConfigLoader' --include='*.php' --exclude-dir=vendor .

# 2. The make:* command classes and the CLI class (Cases 8, 9)
grep -rnE 'Make(Agent|Middleware|Node|Tool|Rag|Workflow|Event|Evaluators)Command|MakeCommand|NeuronCli' --include='*.php' --exclude-dir=vendor .

# 3. Where the evaluation command runs: CI files, composer scripts, Makefiles, shell scripts (Case 6)
grep -rnE 'neuron[[:space:]].*evaluation' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

Follow the hits:

- For every `use ... as Alias;` of these classes, search for `Alias` too.
- Pattern 1 finds `ConfigLoader` only where it is imported or written fully qualified. In those files, look for a
  class that extends it (Case 7).
- For every class that extends `EvaluationCommand`, `EvaluationOutputResolver`, `ConfigLoader`, `MakeCommand`, a
  `Make*Command` class or `NeuronCli`, search for its own name. That finds where it is built or registered, for example
  in a console kernel or a service container.
- Pattern 3, the `run([...])` calls on the command, and the `discover()` calls tell you which directories the
  evaluations run on. Case 6 needs that list.

If patterns 1 and 2 find nothing, and the application never runs `neuron evaluation`, this guide does not apply. If
only pattern 3 finds something, apply Case 6 only.

## How to Refactor

### Case 1: `EvaluationCommand` constructed with an output resolver

Before (3.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;

$command = new EvaluationCommand(new ConfigLoader(), new EvaluationOutputResolver());
// or
$command = new EvaluationCommand(driverResolver: new EvaluationOutputResolver());

exit($command->run($argv));
```

After (4.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Config\ConfigLoader;

$command = new EvaluationCommand(new ConfigLoader());
// or
$command = new EvaluationCommand();

exit($command->run($argv));
```

1. Remove the output resolver argument, whether it is the second positional argument or the named `driverResolver:`
   argument. If you leave it, the call fails with
   `TypeError: ... Argument #2 ($discovery) must be of type NeuronAI\Evaluation\EvaluatorDiscovery` or with
   `Error: Unknown named parameter $driverResolver`.
2. If that argument is an application subclass of `EvaluationOutputResolver`, apply Case 3 instead.
3. If `null` is passed for either argument, remove it. `$configLoader` is no longer nullable.
4. Apply the same change to `parent::__construct($configLoader, $driverResolver)` in an `EvaluationCommand`
   subclass.

`new EvaluationCommand()` and `new EvaluationCommand($configLoader)` do not change.

### Case 2: `EvaluationOutputResolver` constructed directly

Before (3.x):

```php
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;

$drivers = (new EvaluationOutputResolver())->resolve((new ConfigLoader())->getOutputDrivers());
```

After (4.x):

```php
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;

$drivers = (new EvaluationOutputResolver(fn (string $class): object => new $class()))
    ->resolve((new ConfigLoader())->getOutputDrivers());
```

`fn (string $class): object => new $class()` keeps the 3.x behaviour. The argument must be a `Closure`: an arrow
function, a closure, or first-class callable syntax such as `$container->get(...)`. An array callable such as
`[$container, 'get']` throws a `TypeError`. If the resolver was built only to be passed to `EvaluationCommand`,
delete it instead (Case 1).

### Case 3: An application subclass of `EvaluationOutputResolver`

In 3.x, to build output drivers that need dependencies, you overrode the protected `resolveOne()` and passed the
subclass as `driverResolver`. 4.x has no such parameter. The command builds its own `EvaluationOutputResolver` around
the resolver.

Before (3.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Config\EvaluationOutputResolver;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use Psr\Container\ContainerInterface;

class ContainerOutputResolver extends EvaluationOutputResolver
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    protected function resolveOne(string|EvaluationOutputInterface $driver): EvaluationOutputInterface
    {
        if (is_string($driver) && $this->container->has($driver)) {
            return $this->container->get($driver);
        }

        return parent::resolveOne($driver);
    }
}

exit((new EvaluationCommand(driverResolver: new ContainerOutputResolver($container)))->run($argv));
```

After (4.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;

exit((new EvaluationCommand(
    resolver: fn (string $class): object => $container->has($class) ? $container->get($class) : new $class(),
))->run($argv));
```

1. Move the code in `resolveOne()` that builds objects into a closure that takes a class name and returns the object.
   The command calls it for every class-string entry under `output`, after checking that the class exists and
   implements `EvaluationOutputInterface`. Instances listed under `output` are used as they are. The command also
   calls the closure for every discovered evaluator class.
2. For the classes the old code passed on to `parent::resolveOne()`, keep a `new $class()` fallback. Evaluators and
   the other drivers are then built as in 3.x.
3. Pass the closure as `resolver:` where the command is constructed. If the application runs the evaluations with
   `vendor/bin/neuron evaluation` instead, set the closure as the `'resolver'` entry of `evaluation.php`.
4. Delete the subclass when nothing else uses it. If other code still uses it, its constructor must call
   `parent::__construct(fn (string $class): object => new $class())`. Without that call, class-string drivers fail
   with `Typed property ...EvaluationOutputResolver::$resolver must not be accessed before initialization`.

### Case 4: An application subclass of `EvaluationCommand`

In 3.x a subclass could only reach `__construct()` and `run()`. Every other method was private, so a subclass method
with the same name never ran. Evaluators that need constructor arguments were always refused. In 4.x those methods
are protected. PHP checks every redeclaration when the class loads, and a compatible redeclaration now replaces the
parent's method.

Before (3.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\BaseEvaluator;
use Psr\Container\ContainerInterface;

final class ContainerEvaluationCommand extends EvaluationCommand
{
    public function __construct(protected readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function createEvaluator(string $className): BaseEvaluator
    {
        return $this->container->get($className);
    }
}

exit((new ContainerEvaluationCommand($container))->run($argv));
```

In 3.x this `createEvaluator()` never ran. In 4.x the class fails to load:
`Declaration of ContainerEvaluationCommand::createEvaluator(string $className): NeuronAI\Evaluation\BaseEvaluator must be compatible with NeuronAI\Console\Evaluation\EvaluationCommand::createEvaluator(string $className, Closure $resolver): NeuronAI\Evaluation\Contracts\EvaluatorInterface`.

After (4.x):

```php
use NeuronAI\Console\Evaluation\EvaluationCommand;

exit((new EvaluationCommand(
    resolver: fn (string $class): object => $container->get($class),
))->run($argv));
```

1. If the subclass exists only to build evaluators through `createEvaluator()`, delete it. Construct
   `EvaluationCommand` with `resolver:` as shown, and update the places that build or register the subclass. The
   container now builds the evaluators for the first time.
2. The resolver also builds the class-string drivers under `output` (`ConsoleOutput` by default). If the container
   cannot build a class it has no definition for, use
   `fn (string $class): object => $container->has($class) ? $container->get($class) : new $class()`.
3. If the subclass has other logic, keep it:
   - Its constructor passes `resolver:` to `parent::__construct()` instead of overriding `createEvaluator()`, and
     follows Case 1 for the other arguments.
   - For each method whose name matches one of the parent methods below, rename it and its call sites. In 3.x such a
     method was only a helper of the subclass. If it looks meant to change the command itself, for example a custom
     `printError()` or `printUsage()`, ask the developer whether it should now take effect. If yes, make it
     `protected` (or `public`) with the exact signature:
     - `createEvaluator(string $className, Closure $resolver): EvaluatorInterface`
     - `parseArguments(array $args): array`
     - `executeEvaluations(string $path, bool $verbose, int $concurrency, bool $cache, bool $fresh): int`
     - `getShortClassName(string $fullClassName): string`
     - `printUsage(): void`
     - `printProgress(EvaluationResults $results): void`
     - `outputSummary(EvaluationReport $report, Closure $resolver): void`
     - `instantiate(string $className): object`
     - `describeError(Throwable $e): string`
     - `escapeControlCharacters(string $text): string`
     - from `NeuronAI\Console\Command`: `printError(string $message): void` (writes to STDERR),
       `printWarning(string $message): void`, `printSuccess(string $message): void`, and the public
       `setErrorStream(mixed $stream): static`

     The types come from `NeuronAI\Evaluation\Contracts\EvaluatorInterface`,
     `NeuronAI\Evaluation\Runner\EvaluationResults`, `NeuronAI\Evaluation\Runner\EvaluationReport`, `Closure` and
     `Throwable`.
   - Rename subclass properties named `$configLoader`, `$discovery`, `$runner`, `$resolver` or `$errorStream`. They
     collide with protected properties of the parent.

An override of `public function run(array $args): int` stays compatible.

### Case 5: `EvaluatorDiscovery` references

Before (3.x):

```php
use NeuronAI\Evaluation\Discovery\EvaluatorDiscovery;

$classes = (new EvaluatorDiscovery())->discover(__DIR__ . '/evaluators');
```

After (4.x):

```php
use NeuronAI\Evaluation\EvaluatorDiscovery;

$classes = (new EvaluatorDiscovery())->discover(__DIR__ . '/evaluators');
```

Replace `NeuronAI\Evaluation\Discovery\EvaluatorDiscovery` with `NeuronAI\Evaluation\EvaluatorDiscovery` in `use`
statements, fully qualified names, class-name strings and docblocks. `discover(string $path): array` and the
protected `getPhpFiles()`, `getClassesFromFile()` and `isEvaluatorClass()` keep their signatures.

### Case 6: Evaluators that 3.x skipped now run

3.x discovery only recognized declarations that start with `class` at the beginning of a line. It silently skipped
evaluators declared `final class ...`, `readonly class ...`, or indented. 4.x finds every concrete
`EvaluatorInterface` class in the directory and runs them in order of fully qualified class name.

1. For each evaluator directory found by the search, run:

   ```bash
   grep -rnE '^[[:space:]]*((final|readonly)[[:space:]]+)+class[[:space:]]+[A-Za-z_]|^[[:space:]]+class[[:space:]]+[A-Za-z_]' --include='*.php' <evaluator-dir>
   ```

2. Every hit that is a non-abstract evaluator will now run, with its AI provider calls. That means it extends
   `BaseEvaluator` or implements `EvaluatorInterface`, directly or through an application base class. List these
   evaluators for the developer and ask whether each one should run. For the ones that should not, move them out of
   the directory or declare them `abstract`.
3. For the ones that stay, check that the command can build them and that their dataset exists. The command can build
   an evaluator when its constructor has no required parameters, or when a resolver is configured (Cases 3 and 4).
4. Evaluators now run sorted by class name. If a script depended on the old order, which was filesystem order,
   update it.

### Case 7: An application subclass of `ConfigLoader`

`ConfigLoader` now declares these members:

- `protected ?array $config`
- `protected function readConfig(): array`
- `public function getResolver(): ?Closure`
- `public function getRunner(): ?EvaluatorRunner`
- `public function getCachePath(): string`

A subclass that declares a member with one of these names no longer loads. Example error:
`Access level to ArrayConfigLoader::$config must be protected (as in class NeuronAI\Evaluation\Config\ConfigLoader) or weaker`.

Before (3.x):

```php
use NeuronAI\Evaluation\Config\ConfigLoader;

class ArrayConfigLoader extends ConfigLoader
{
    public function __construct(private array $config)
    {
    }

    public function load(): array
    {
        return $this->config;
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\Config\ConfigLoader;

class ArrayConfigLoader extends ConfigLoader
{
    public function __construct(private array $settings)
    {
    }

    public function load(): array
    {
        return $this->settings;
    }
}
```

Rename the colliding member and its uses. If you rename a promoted constructor parameter, also update any named
arguments that pass it (`config:`). An override of `load()` keeps working: 4.x reads `output`, `resolver`, `runner`
and `cache` through it.

### Case 8: Code that instantiates the removed `Make*Command` classes

| 3.x | 4.x |
|---|---|
| `new MakeAgentCommand()` | `new MakeCommand('make:agent', 'Agent', 'agent.stub')` |
| `new MakeMiddlewareCommand()` | `new MakeCommand('make:middleware', 'Middleware', 'middleware.stub')` |
| `new MakeNodeCommand()` | `new MakeCommand('make:node', 'Node', 'node.stub')` |
| `new MakeToolCommand()` | `new MakeCommand('make:tool', 'Tool', 'tool.stub')` |
| `new MakeRagCommand()` | `new MakeCommand('make:rag', 'RAG', 'rag.stub')` |
| `new MakeWorkflowCommand()` | `new MakeCommand('make:workflow', 'Workflow', 'workflow.stub')` |
| `new MakeEventCommand()` | `new MakeCommand('make:event', 'Event', 'event.stub')` |
| `new MakeEvaluatorsCommand()` | `new MakeCommand('make:evaluators', 'Evaluator', 'evaluator.stub')` |

Before (3.x):

```php
use NeuronAI\Console\Make\MakeAgentCommand;

$exitCode = (new MakeAgentCommand())->run(['neuron', $className]);
```

After (4.x):

```php
use NeuronAI\Console\Make\MakeCommand;

$exitCode = (new MakeCommand('make:agent', 'Agent', 'agent.stub'))->run(['neuron', $className]);
```

1. Replace each instantiation using the table, and replace the imports with `use NeuronAI\Console\Make\MakeCommand;`.
   `run()` takes the same arguments: the script name first, then the class name.
2. Where the code keeps these classes as `::class` strings and builds them later, for example in a command registry,
   store a `MakeCommand` instance or a closure that builds one.
3. Alternatively, go through the CLI class: `(new NeuronCli())->run(['neuron', 'make:agent', $className])`
   (`use NeuronAI\Console\NeuronCli;`).

### Case 9: A class extending `MakeCommand`, a removed `Make*Command` class, or `NeuronCli`

Before (3.x):

```php
use NeuronAI\Console\Make\MakeCommand;
use NeuronAI\Console\Make\MakeToolCommand;

class MakeGuardrailCommand extends MakeCommand
{
    public function __construct()
    {
        parent::__construct('Guardrail');
    }

    protected function getStubContent(string $namespace, string $className): string
    {
        $stub = (string) file_get_contents(__DIR__ . '/stubs/guardrail.stub');

        return str_replace(['[namespace]', '[classname]'], [$namespace, $className], $stub);
    }
}

class MakeAppToolCommand extends MakeToolCommand
{
    protected function getStubContent(string $namespace, string $className): string
    {
        $stub = (string) file_get_contents(__DIR__ . '/stubs/tool.stub');

        return str_replace(['[namespace]', '[classname]'], [$namespace, $className], $stub);
    }
}
```

After (4.x):

```php
use NeuronAI\Console\Make\MakeCommand;

class MakeGuardrailCommand extends MakeCommand
{
    public function __construct()
    {
        parent::__construct('make:guardrail', 'Guardrail', 'guardrail.stub');
    }

    protected function getStubContent(string $namespace, string $className): string
    {
        $stub = (string) file_get_contents(__DIR__ . '/stubs/guardrail.stub');

        return str_replace(['[namespace]', '[classname]'], [$namespace, $className], $stub);
    }
}

class MakeAppToolCommand extends MakeCommand
{
    public function __construct()
    {
        parent::__construct('make:tool', 'Tool', 'tool.stub');
    }

    protected function getStubContent(string $namespace, string $className): string
    {
        $stub = (string) file_get_contents(__DIR__ . '/stubs/tool.stub');

        return str_replace(['[namespace]', '[classname]'], [$namespace, $className], $stub);
    }
}
```

1. Change `parent::__construct($resourceType)` to `parent::__construct($commandName, $resourceType, $stubFile)`.
   - `$commandName` is shown only in the usage text. 3.x showed `make:<resourceType>` there.
   - `$resourceType` is used in messages, as in 3.x.
   - `$stubFile` names a file in Neuron's own stub directory. Only the default `getStubContent()` reads it.
2. A subclass of a removed `Make*Command` class now extends `MakeCommand`. Give it a constructor that passes that
   class's three arguments from the Case 8 table, so its call sites stay unchanged.
3. Keep the `getStubContent()` override: it supplies the application's own stub. Without it, `MakeCommand` reads
   `Stubs/<stubFile>` from the framework.
4. The members below are now defined by the parent. Make each redeclaration `protected` (or `public`) with a
   compatible signature, or rename it and its call sites.
   - `MakeCommand`, formerly private methods: `parseArguments(array $args): array`,
     `generateClass(string $name): int`, `parseNamespaceAndClass(string $name): array`,
     `getDefaultNamespace(): string`, `getFilePath(string $namespace, string $className): string`,
     `loadPsr4Config(): array`, `namespaceBelongsToPsr4(string $namespace): bool`,
     `printAvailableNamespaces(): void`
   - `MakeCommand`, new members: `validateClassName(string $name): void`, the constant `RESERVED_CLASS_NAMES`, and
     the properties `$commandName` and `$stubFile`
   - `NeuronCli`, formerly private: `printUsage(): void`. New: the public `commands(): array`, which returns the
     command list
   - Both, from `NeuronAI\Console\Command`: `printError(string $message): void`,
     `printWarning(string $message): void`, `printSuccess(string $message): void`, the public
     `setErrorStream(mixed $stream): static`, and the property `$errorStream`

   Overrides of `MakeCommand`'s `printUsage()`, `printError()`, `printWarning()` and `printSuccess()` keep working.
   They were already protected in 3.x.

## Checklist

- No `driverResolver` argument remains. No `EvaluationOutputResolver` and no `null` is passed to
  `new EvaluationCommand(...)` or to `parent::__construct(...)` in a command subclass.
- Every `new EvaluationOutputResolver(...)` passes a `Closure`.
- No `EvaluationOutputResolver` subclass is still meant for the command. Its logic is in a `resolver` closure, and any
  subclass that remains calls `parent::__construct()` with a `Closure`.
- No `EvaluationCommand` subclass declares `createEvaluator(string $className): BaseEvaluator`. No subclass declares
  a private or incompatible member with a name listed in Case 4.
- No reference to `NeuronAI\Evaluation\Discovery\` remains.
- The evaluators found by the Case 6 grep were reviewed with the developer. The ones that stay in the directory can
  be built.
- No `ConfigLoader` subclass declares a member listed in Case 7.
- No reference to the eight removed `Make*Command` classes remains. Every `MakeCommand` subclass passes three
  constructor arguments and keeps its `getStubContent()` override.
- Signature and visibility collisions only show up when the class loads. If the application runs PHPStan, it reports
  them (`method.visibility`, `parameter.missing`, `property.visibility`). Otherwise load each changed subclass once,
  for example `php -r "require 'vendor/autoload.php'; class_exists(App\Console\ContainerEvaluationCommand::class);"`.
