# Evaluations in Laravel: the command, the evaluators and the plain CLI

These evaluators ran through `php artisan neuron:evaluate` (Setup, step 7 of the skill) against MySQL 8.4 with `--env=evaluation --concurrency=4`: every item passed, the tools read and refunded orders in the evaluation database from forked children, `chat_messages` and `workflow_store` stayed empty, and the parent process kept its database connection. Assertion, dataset and judge semantics are in **neuron-evaluation**.

Both live in `app/Neuron/Evaluators`, generated with `vendor/bin/neuron make:evaluators 'App\Neuron\Evaluators\<Name>'`; their datasets in `app/Neuron/Evaluators/datasets`.

## The command

`php artisan make:command NeuronEvaluate`, then replace the class. The Setup section of the skill explains the `chdir()`, the resolver, the child hooks and the refusal.

```php
namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

#[Signature('neuron:evaluate {path=app/Neuron/Evaluators} {--concurrency=1} {--cache} {--fresh}')]
#[Description('Run the Neuron evaluators, built by the application container')]
class NeuronEvaluate extends Command
{
    /** The connections a forked child inherited: closing one there would close the parent's too. */
    protected array $inherited = [];

    public function handle(): int
    {
        // --env=evaluation falls back to .env when .env.evaluation is missing or config is cached: the tools would write there.
        if ($this->laravel->environment('evaluation') && $this->laravel->environmentFile() !== '.env.evaluation') {
            $this->error('.env.evaluation was not loaded: create it, and run php artisan config:clear.');

            return self::FAILURE;
        }

        // Neuron reads evaluation.php from the working directory.
        chdir($this->laravel->basePath());

        $evaluation = new EvaluationCommand(
            runner: new EvaluatorRunner(beforeChild: $this->reconnect(...), afterChild: $this->disconnect(...)),
            resolver: fn (string $class): object => $this->laravel->make($class),
        );

        return $evaluation->run(array_filter([
            'neuron',
            $this->argument('path'),
            '--concurrency='.$this->option('concurrency'),
            $this->option('cache') ? '--cache' : null,
            $this->option('fresh') ? '--fresh' : null,
            $this->output->isVerbose() ? '--verbose' : null,
        ]));
    }

    /** In each forked child, before its item: keep the inherited connections referenced, open new ones. */
    protected function reconnect(): void
    {
        foreach (DB::getConnections() as $name => $connection) {
            $this->inherited[] = [$connection->getRawPdo(), $connection->getRawReadPdo()];
            DB::purge($name);
        }
    }

    /** In each forked child, after its item: close the connections the child opened. */
    protected function disconnect(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::disconnect($name);
        }
    }
}
```

```php
// evaluation.php, at the project root
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [
        ConsoleOutput::class,
        new JsonOutput(storage_path('logs/evaluation.json')),
    ],
    // Where --cache keeps the outputs of run().
    'cache' => ['path' => storage_path('framework/cache/evaluation')],
];
```

## The evaluation database

Evaluations run the real tools: approving a refund refunds that order. They run on a database of their own, selected with `--env=evaluation` from `.env.evaluation`, and rebuilt before every run, CI included, so every run starts from the data the datasets name:

```bash
php artisan migrate:fresh --seed --seeder=EvaluationSeeder --env=evaluation
php artisan neuron:evaluate --env=evaluation --concurrency=4
```

```php
namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The customer and the orders the evaluation datasets name. Evaluations refund orders for real: seed before every run.
 */
class EvaluationSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::factory()->create(); // customer 1 on a fresh database

        Collection::times(8, fn (): Order => Order::create(['user_id' => $customer->id, 'status' => 'shipped', 'total' => 40]));
    }
}
```

- **`--env` can silently select `.env`.** Laravel ignores `--env` while `bootstrap/cache/config.php` exists (after `config:cache` or `optimize`), and falls back to `.env` when `.env.evaluation` is missing. Both times the environment still reads `evaluation`, but the tools write to the main database. `neuron:evaluate` checks `environmentFile()` and stops with exit code 1 in both cases.
- The seeder runs on a fresh database, so the customer is user 1 and the orders are 1 to 8, the IDs the datasets name.

## A single-turn evaluator

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Tools\LookupOrder;
use App\Neuron\Tools\RefundOrder;
use Illuminate\Support\Str;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;

class OrderAnswerEvaluator extends BaseEvaluator
{
    public function __construct(protected SupportAgent $agent)
    {
        parent::__construct();

        // Setters beat the store hooks: evaluation turns never reach chat_messages or workflow_store.
        $this->agent->setMessageStore(new InMemoryMessageStore())->setPersistence(new InMemoryPersistence());
    }

    /** --cache replays run() until one of these files changes: the agent's prompt and tools live there. */
    public function cacheDependencies(): array
    {
        return [SupportAgent::class, LookupOrder::class, RefundOrder::class];
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__.'/datasets/order-answers.json');
    }

    public function run(array $datasetItem): mixed
    {
        // A fresh thread per item, named like the app's own: the tools act for that customer.
        return $this->agent->for("user-{$datasetItem['customer']}-".Str::uuid())
            ->chat(new UserMessage($datasetItem['question']))
            ->getMessage()
            ->getContent();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}
```

`datasets/order-answers.json`, against an evaluation database seeded with customer 1 and its orders:

```json
[
    {"customer": 1, "question": "What is the status of order 1?", "expected": "shipped"},
    {"customer": 1, "question": "How much was order 2?", "expected": "40 EUR"},
    {"customer": 1, "question": "Tell me about order 3", "expected": "Order 3"},
    {"customer": 1, "question": "And order 4?", "expected": "Order 4"}
]
```

- Without `parent::__construct()`, every item fails with `Error: Typed property NeuronAI\Evaluation\BaseEvaluator::$ruleExecutor must not be accessed before initialization` before `evaluate()` runs.
- `cacheDependencies()` names the files `run()` depends on beyond itself. `--cache` replays a cached output until `run()`, its dataset item or one of these files changes: without the list, a change to the agent's prompt still reported `Cached runs: 8 of 8` and called no model; with it, every item ran again. Add any file the tools or the prompt read.
- The container builds a new `SupportAgent` for this evaluator, so the in-memory stores stay on it and on the copies `for()` makes; a later `app(SupportAgent::class)` still resolves `EloquentMessageStore` and `DatabasePersistence`. The in-memory store keys messages by thread, so one instance serves every item.

## A conversation evaluator

`Conversation` binds its own copy of the agent to an `eval_<uuid>` thread for every run, and the copy shares what was set on the agent. Those threads name no customer, so `SupportAgent::customerId()` refuses them (`LogicException: Thread 'eval_…' names no customer.`): pin the tools to the evaluation customer. A setter beats the `tools()` hook, so the hook never runs; keep the list in step with `SupportAgent::tools()`.

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Tools\LookupOrder;
use App\Neuron\Tools\RefundOrder;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;

class RefundConversationEvaluator extends BaseEvaluator
{
    /** The customer the evaluation database is seeded with. */
    protected const CUSTOMER = 1;

    public function __construct(protected SupportAgent $agent)
    {
        parent::__construct();

        // Conversation names its own threads ("eval_…"), so the tools cannot read the customer from them: pin them.
        $this->agent
            ->setMessageStore(new InMemoryMessageStore())
            ->setPersistence(new InMemoryPersistence())
            ->setTools([LookupOrder::make(self::CUSTOMER), RefundOrder::make(self::CUSTOMER)]);
    }

    /** --cache replays run() until one of these files changes: the agent's prompt and tools live there. */
    public function cacheDependencies(): array
    {
        return [SupportAgent::class, LookupOrder::class, RefundOrder::class];
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__.'/datasets/refund-conversations.json');
    }

    public function run(array $datasetItem): mixed
    {
        return Conversation::make($this->agent)
            ->withTurns($datasetItem['turns'])
            ->withApprovals(fn (ApprovalRequest $request): array => array_fill_keys(
                array_map(fn ($action): string => $action->id, $request->getActions()),
                'approve',
            ))
            ->run();
    }

    public function evaluate(mixed $trajectory, array $datasetItem): void
    {
        $this->assert(new TrajectoryMatches(['lookup_order', 'refund_order']), $trajectory);
        $this->assert(new ToolWasApproved('refund_order'), $trajectory);
        $this->assert(new StringContains('refunded'), $trajectory->finalAnswer());
    }
}
```

`datasets/refund-conversations.json`, one order per item: the refunds are real writes in the evaluation database, and parallel items must not share one.

```json
[
    {"turns": ["Where is order 5?", "Please refund order 5"]},
    {"turns": ["Where is order 6?", "Please refund order 6"]},
    {"turns": ["Where is order 7?", "Please refund order 7"]},
    {"turns": ["Where is order 8?", "Please refund order 8"]}
]
```

A run left `chat_messages` and `workflow_store` empty while the trajectory held both tool calls and the answer, and the order was refunded. With `--cache`, unchanged items skip `run()`, so neither the LLM nor the tools are called again.

## Without Artisan: `vendor/bin/neuron`

The Neuron CLI loads `--autoload-file` before its own autoloader and reads `evaluation.php` from the working directory, so it runs from the project root with a bootstrap file that boots the console kernel:

```php
// bootstrap/neuron.php
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
```

```bash
vendor/bin/neuron evaluation app/Neuron/Evaluators --autoload-file=bootstrap/neuron.php --concurrency=4
```

Laravel reads `--env=evaluation` from the same command line, so the evaluation database is selected as with Artisan, with the same two traps (cached config, a missing `.env.evaluation`) and no check against them. `evaluation.php` then carries what `neuron:evaluate` passes itself, the container resolver and the runner with the child hooks. Without the `runner` entry, `--concurrency=4` failed items with "Premature end of data" on MySQL.

```php
// evaluation.php
use Illuminate\Support\Facades\DB;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

return [
    'output' => [
        ConsoleOutput::class,
        new JsonOutput(storage_path('logs/evaluation.json')),
    ],
    // Where --cache keeps the outputs of run().
    'cache' => ['path' => storage_path('framework/cache/evaluation')],

    // Read by vendor/bin/neuron only: neuron:evaluate passes its own resolver and runner.
    'resolver' => fn (string $class): object => app($class),
    'runner' => new EvaluatorRunner(
        beforeChild: function (): void {
            static $inherited = []; // closing an inherited connection in a child closes the parent's too

            foreach (DB::getConnections() as $name => $connection) {
                $inherited[] = [$connection->getRawPdo(), $connection->getRawReadPdo()];
                DB::purge($name);
            }
        },
        afterChild: function (): void {
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::disconnect($name);
            }
        },
    ),
];
```

Launched from another directory, the CLI finds no `evaluation.php` and fails every evaluator with "requires constructor arguments: build it with a resolver". The Artisan command has neither limit and keeps the resolver and the hooks in one class.

## What the child hooks prevent

Observed with eight items, `--concurrency=4`, MySQL 8.4, and a parent process that queried the database before and after the run:

| Child hook | Children | Parent after the run |
|---|---|---|
| none | items failed with "Premature end of data" or wrong counts: the children shared the parent's socket | its next query failed |
| `DB::purge()` only | all passed, each on its own connection | its session was closed on the server; Laravel reconnected with a new connection ID |
| keep the inherited PDOs, then `DB::purge()` | all passed, each on its own connection | same session and connection ID as before |
| `afterChild` disconnecting | — | MySQL counted no aborted clients (eight without it) |

`spatie/fork` ends every child with `SIGKILL`, so the PDOs kept in `$inherited` are never destroyed there. That takes `ext-posix`: without it a child would end with `exit()` and destroy them, so the runner does not fork and `--concurrency` runs sequentially.

The hooks replace the connections Laravel's `DB` manager holds, not a PDO an object captured in the parent. The container's `DatabasePersistence` is one: kept on an evaluator's agent, all eight items failed ("MySQL server has gone away", "Premature end of data", "Packets out of order") and the parent's connection was left answering wrong (`Schema::hasTable()` returned false for an existing table). Built inside `run()`, in the child, the same persistence passed. Evaluators keep `InMemoryPersistence`.
