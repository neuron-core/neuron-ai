# Upgrade: The evaluation command builds classes through a resolver

## Summary

- **`EvaluationCommand` builds evaluators and output drivers given as class names through a resolver**, a
  `callable(class-string): object` passed to its constructor or set as the `resolver` entry of `evaluation.php`.
  It used to refuse any evaluator whose constructor had required parameters, so using a container meant
  subclassing the command and overriding `createEvaluator()`. That method now receives the resolver, so an
  override written for the old signature no longer loads.
- **The constructor drops the `driverResolver` parameter.** Its parameters are `configLoader`, `discovery`,
  `runner` and `resolver`. `runner` defaults to the `runner` entry of `evaluation.php`, then to a plain
  `EvaluatorRunner`; `--cache` and `--fresh` now apply to that runner instead of replacing it.
- **`EvaluationOutputResolver` takes the resolver in its constructor** and builds class-string drivers with it.

| Before | After |
|---|---|
| `protected function createEvaluator(string $className): BaseEvaluator` | `protected function createEvaluator(string $className, Closure $resolver): EvaluatorInterface` |
| `new EvaluationCommand($configLoader, $driverResolver, $discovery, $runner)` | `new EvaluationCommand($configLoader, $discovery, $runner, $resolver)` |
| `new EvaluationOutputResolver()` | `new EvaluationOutputResolver($resolver)` |

## How to Refactor

### Case 1: A command subclass that overrides `createEvaluator()`

Pass the container as the resolver instead of subclassing.

Before:

```php
final class EvaluationRunner extends EvaluationCommand
{
    public function __construct(protected readonly Container $container)
    {
        parent::__construct();
    }

    protected function createEvaluator(string $className): BaseEvaluator
    {
        return $this->container->make($className);
    }
}

$exitCode = (new EvaluationRunner($container))->run($args);
```

After:

```php
$exitCode = (new EvaluationCommand(
    resolver: fn (string $class): object => $container->make($class),
))->run($args);
```

A subclass that also overrides other methods, such as `printError()`, keeps them and passes the resolver to
the parent constructor.

### Case 2: Code passing constructor arguments by position or as `driverResolver`

Drop the output resolver: the command builds it with its resolver.

Before:

```php
new EvaluationCommand($configLoader, new EvaluationOutputResolver(), $discovery, $runner);
```

After:

```php
new EvaluationCommand($configLoader, $discovery, $runner);
```

### Case 3: Code constructing `EvaluationOutputResolver`

Before:

```php
$drivers = (new EvaluationOutputResolver())->resolve($config->getOutputDrivers());
```

After:

```php
$drivers = (new EvaluationOutputResolver(fn (string $class): object => $container->get($class)))
    ->resolve($config->getOutputDrivers());
```

Use `fn (string $class): object => new $class()` to keep instantiating drivers directly.

## What to Search For

```
grep -rn "extends EvaluationCommand\|createEvaluator(" --include="*.php" .
grep -rn "new EvaluationCommand(\|driverResolver" --include="*.php" .
grep -rn "EvaluationOutputResolver" --include="*.php" .
```

## Checklist

- No class overrides `createEvaluator()` with the old signature; container resolution goes through `resolver`.
- No `EvaluationCommand` construction passes an output resolver, by position or by name.
- Every `EvaluationOutputResolver` construction passes a resolver.
