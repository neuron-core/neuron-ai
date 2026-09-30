# Evaluations in Laravel: the evaluators and the plain CLI

These evaluators ran through `php artisan neuron:evaluate` (Setup, step 7 of the skill) against MySQL 8.4 with `--env=evaluation --concurrency=4`: every item passed, the tools read and refunded orders in the evaluation database from forked children, `chat_messages` and `workflow_store` stayed empty, and the parent process kept its database connection. Assertion, dataset and judge semantics are in **neuron-evaluation**.

Both live in `app/Neuron/Evaluators`, generated with `vendor/bin/neuron make:evaluators 'App\Neuron\Evaluators\<Name>'`; their datasets in `app/Neuron/Evaluators/datasets`.

## A single-turn evaluator

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
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
    {"customer": 1, "question": "How much was order 2?", "expected": "40 EUR"}
]
```

- Without `parent::__construct()`, the first `assert()` throws `Error: Typed property NeuronAI\Evaluation\BaseEvaluator::$ruleExecutor must not be accessed before initialization`.
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
    {"turns": ["Where is order 6?", "Please refund order 6"]}
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

Laravel reads `--env=evaluation` from the same command line, so the evaluation database is selected as with Artisan. `evaluation.php` then carries what `neuron:evaluate` passes itself, the container resolver and the runner with the child hooks. Without the `runner` entry, `--concurrency=4` failed items with "Premature end of data" on MySQL.

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

`spatie/fork` ends every child with `SIGKILL` when `ext-posix` is loaded, so the PDOs kept in `$inherited` are never destroyed there. Without `ext-posix` a child ends with `exit()`, which destroys them: the items still passed, but the parent's session was closed as with `DB::purge()` only.

The hooks replace the connections Laravel's `DB` manager holds, not a PDO an object captured in the parent. The container's `DatabasePersistence` is one: kept on an evaluator's agent, all eight items failed ("MySQL server has gone away", "Premature end of data", "Packets out of order") and the parent's connection was left answering wrong (`Schema::hasTable()` returned false for an existing table). Built inside `run()`, in the child, the same persistence passed. Evaluators keep `InMemoryPersistence`.
