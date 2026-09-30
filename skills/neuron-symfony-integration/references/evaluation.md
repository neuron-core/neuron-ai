# Evaluating the app's agents

SKILL.md lists the commands and explains the parts (Setup step 7 and Evaluation). This reference has the full command, the evaluators, where they live and why, and what running them showed. Evaluators, datasets, assertions, judges, `Conversation` and `Trajectory` are owned by **neuron-evaluation**.

## The command

```php
namespace App\Command;

use Doctrine\DBAL\Connection;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Filesystem\Path;

#[AsCommand('neuron:evaluate', 'Run the Neuron evaluators of a directory')]
class NeuronEvaluateCommand
{
    /** The connection a forked child inherited: destroying it would close the parent's. */
    protected mixed $inherited = null;

    public function __construct(
        #[AutowireLocator('neuron.evaluation')] protected ContainerInterface $services,
        protected Connection $connection,
        #[Autowire('%kernel.project_dir%')] protected string $projectDir,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('Directory of the evaluators (default: src/Neuron/Evaluators)')] ?string $path = null,
        #[Option('Run dataset items in N parallel processes')] int $concurrency = 1,
        #[Option('Serve unchanged run() outputs from the evaluation cache')] bool $cache = false,
        #[Option('Re-run everything and overwrite the evaluation cache')] bool $fresh = false,
    ): int {
        $args = [
            'neuron',
            $path === null ? "{$this->projectDir}/src/Neuron/Evaluators" : Path::makeAbsolute($path, getcwd()),
            "--concurrency={$concurrency}",
            ...($cache ? ['--cache'] : []),
            ...($fresh ? ['--fresh'] : []),
            ...($output->isVerbose() ? ['--verbose'] : []),
        ];

        // evaluation.php, its cache path and relative output paths are read from the working directory.
        chdir($this->projectDir);

        return (new EvaluationCommand(runner: $this->runner(), resolver: $this->resolve(...)))->run($args);
    }

    /** Evaluators and the app's output drivers come from the container; Neuron's own drivers need no arguments. */
    protected function resolve(string $class): object
    {
        return $this->services->has($class) ? $this->services->get($class) : new $class();
    }

    protected function runner(): EvaluatorRunner
    {
        return new EvaluatorRunner(
            beforeChild: function (): void {
                if ($this->connection->isConnected()) {
                    $this->inherited = $this->connection->getNativeConnection();
                    $this->connection->close(); // the next query opens this child's own connection
                }
            },
            afterChild: $this->connection->close(...),
        );
    }
}
```

- The locator holds every service tagged `neuron.evaluation` (`_instanceof` in `config/services.yaml`, SKILL.md Setup step 5): evaluators, and output drivers of the app. A driver that needs a Doctrine `Connection`, listed by class name in `evaluation.php`, was built with it and wrote its row after the run. Neuron's `ConsoleOutput` is no service, so `resolve()` builds it with `new`; a driver that needs options goes in `evaluation.php` as an instance, like `JsonOutput`.
- The `beforeChild` hook runs in each forked child before its item. It checks `isConnected()` first, so a child whose parent never connected does not open a connection just to keep it. `afterChild` closes the connection the child opened.
- `-v` sets `--verbose`: evaluator names are printed as they run, and Symfony's console logger shows the app's notices (the refund tool's `neuron.refund_executed`).

## A single-turn evaluator

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Tools\OrderStatusTool;
use App\Neuron\Tools\RefundOrderTool;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use Symfony\Component\Uid\Uuid;

class SupportAgentEvaluator extends BaseEvaluator
{
    /** The customer the evaluation database is seeded with. */
    protected const CUSTOMER = 1;

    public function __construct(protected SupportAgent $agent)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__.'/datasets/support.json');
    }

    public function run(array $datasetItem): mixed
    {
        return $this->agent()->chat(new UserMessage($datasetItem['question']))->getMessage()->getContent();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }

    /** Outside run(): a change to the agent, its prompt or its tools must invalidate the --cache entries. */
    public function cacheDependencies(): array
    {
        return [SupportAgent::class, OrderStatusTool::class, RefundOrderTool::class];
    }

    /** The app's agent on a fresh thread of the evaluation customer, with stores that never touch the conversation tables. */
    protected function agent(): SupportAgent
    {
        return $this->agent->for('user-'.self::CUSTOMER.'-'.Uuid::v4())
            ->setMessageStore(new InMemoryMessageStore())
            ->setPersistence(new InMemoryPersistence());
    }
}
```

```json
[
    {"question": "What is the status of order A-1?", "expected": "shipped"},
    {"question": "Hi there", "expected": "Hello"}
]
```

`src/Neuron/Evaluators/datasets/support.json`. The thread names the customer the evaluation database is seeded with, so the tools act for that customer. The agent, its tools and its prompt are outside `run()`: `cacheDependencies()` names their classes, so `--cache` notices a change to any of them (**neuron-evaluation**, "Run Output Caching").

## A multi-turn evaluator

`Conversation` binds its own copy of the agent it receives with `for()`, to an `eval_…` thread. Hand it the evaluator's copy, which already carries the in-memory stores and the tools pinned to the evaluation customer: `for()` clones the copy, stores and tools included. The thread names no customer, so without the pinned tools `customerId()` refused it (`LogicException: Thread 'eval_…' names no customer.`); a setter wins over the `tools()` hook, so keep the list in step with `SupportAgent::tools()`.

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Tools\OrderStatusTool;
use App\Neuron\Tools\RefundOrderTool;
use App\Repository\OrderRepository;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use Psr\Log\LoggerInterface;

class RefundConversationEvaluator extends BaseEvaluator
{
    /** The customer the evaluation database is seeded with. */
    protected const CUSTOMER = 1;

    public function __construct(
        protected SupportAgent $agent,
        protected OrderRepository $orders,
        protected LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['turns' => ['Hi', 'Please refund order A-2.']],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        // Conversation binds its own copy of this one: the in-memory stores go with it.
        return Conversation::make($this->agent())
            ->withTurns($datasetItem['turns'])
            ->withApprovals(fn (ApprovalRequest $request): array => array_fill_keys(
                array_map(fn ($action): string => $action->id, $request->getActions()),
                'approve',
            ))
            ->run();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new ToolWasCalled('refund_order', ['order_id' => 'A-2']), $output);
        $this->assert(new ToolWasApproved('refund_order'), $output);
        $this->assert(new StringContains('refunded'), $output->finalAnswer());
    }

    public function cacheDependencies(): array
    {
        return [SupportAgent::class, OrderStatusTool::class, RefundOrderTool::class];
    }

    /** Conversation binds "eval_" threads, which name no customer: the tools are pinned to the evaluation customer. */
    protected function agent(): SupportAgent
    {
        return $this->agent->for(UniqueIdGenerator::generateId('eval_'))
            ->setMessageStore(new InMemoryMessageStore())
            ->setPersistence(new InMemoryPersistence())
            ->setTools([
                new OrderStatusTool($this->orders, self::CUSTOMER),
                new RefundOrderTool($this->orders, $this->logger, self::CUSTOMER),
            ]);
    }
}
```

Run on the container's agent: the Conversation's copy was bound to its own `eval_…` thread, held the same `InMemoryMessageStore` and `InMemoryPersistence` instances as the evaluator's copy, and its thread had 6 messages in that store (two user turns, the first answer, the tool call, its result and the final answer). The refund tool was approved and executed: order A-2 was `refunded` in the evaluation database afterwards. Tools run for real during evaluations: run them on a database of their own.

## The evaluation database

Point `DATABASE_URL` at a dedicated database (a real env var wins over `.env`) and rebuild it before every run, CI included, so every run starts from the data the datasets name:

```bash
export DATABASE_URL="mysql://app:secret@127.0.0.1:3306/shop_evaluation?serverVersion=8.4&charset=utf8mb4"
php bin/console doctrine:database:drop --force --if-exists
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load -n --group=evaluation
php bin/console neuron:evaluate --concurrency=4 --cache
```

```php
namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/** The customer and the orders the evaluation datasets name. Evaluations refund orders for real: load before every run. */
class EvaluationFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['evaluation'];
    }

    public function load(ObjectManager $manager): void
    {
        $customer = (new User())->setEmail('customer@example.com')->setPassword('!'); // customer 1 on a fresh database
        $manager->persist($customer);
        $manager->flush();

        foreach (['A-1', 'A-2'] as $reference) {
            $manager->persist(new Order($reference, $customer->getId()));
        }
        $manager->flush();
    }
}
```

- `doctrine/doctrine-fixtures-bundle` (`composer require --dev`) loads the fixture; `--group=evaluation` keeps other fixtures out.
- The database is dropped and created rather than purged: the customer is user 1 only on a fresh database, the ID the evaluators pin.
- Run this way on MySQL 8.4, every item passed, order A-2 was refunded, A-1 stayed `shipped`, and `chat_messages` and `workflow_store` stayed empty.

## Where evaluators live

`src/Neuron/Evaluators`, namespace `App\Neuron\Evaluators`, datasets in `src/Neuron/Evaluators/datasets`. Three things line up there:

- **The generator.** `vendor/bin/neuron make:evaluators 'App\Neuron\Evaluators\SupportAgentEvaluator'` wrote `src/Neuron/Evaluators/SupportAgentEvaluator.php`.
- **Registration.** The default `App\: resource: '../src/'` registers every evaluator as an autowired service, and the `_instanceof` entry in `config/services.yaml` tags it `neuron.evaluation`: `debug:container --tag=neuron.evaluation` listed both evaluators, in every environment.
- **Discovery.** `neuron:evaluate` without a path scans that directory; any other directory works as an argument, relative to where the command is launched.

An `evaluators/` directory under `autoload-dev` (the layout **neuron-evaluation** shows for plain PHP projects) does not fit a Symfony app: `make:evaluators` reads only `autoload.psr-4`, so `App\Evaluators\ProbeEvaluator` landed in `src/Evaluators/` under the `App\` prefix, and the directory would need its own service resource, restricted to dev and test.

Registered in every environment, evaluators are still built only when the command runs: the service locator is lazy.

## What running the command showed

On MySQL 8.4:

| Run | Result |
|---|---|
| `neuron:evaluate` launched from `/tmp` | Found `src/Neuron/Evaluators`, read the project's `evaluation.php`: `var/evaluation.json` written, `--cache` entries in `var/evaluation/cache` |
| Same, without the command's `chdir()` | `evaluation.php` ignored (no JSON report), cache written to `/tmp/.neuron/cache/evaluation` |
| `--cache` twice, then `--cache --concurrency=4`, then `--fresh` | 9 provider calls, then 0 (`Cached runs: 5 of 5`), 0, then 9 again |
| `--cache` after a change to the agent's prompt, with `cacheDependencies()` | No cached run: every item ran again (9 provider calls) |
| The same without `cacheDependencies()` | `Cached runs: 5 of 5` and no provider call: the outputs of the old prompt |
| The conversation evaluator without pinned tools | `LogicException: Thread 'eval_…' names no customer.` |
| `--concurrency=4`, an evaluator querying the database in `setUp()` (parent) and in `run()` (8 items) | Each child on its own connection (IDs 154 to 161), the parent's connection (153) still open: an output driver wrote the report row on it after the children |
| Same, with no child hooks | The children shared connection 163; 2 of 8 items failed with `2006 MySQL server has gone away` |
| Same, with a `beforeChild` that only called `$connection->close()` | Children fine, the parent's connection closed by them: the output driver failed with `2006 MySQL server has gone away` |
| An evaluator without the in-memory stores | Its `eval_…` thread appeared in `chat_messages` (a user and an assistant row) |
| The two evaluators above, several runs | `chat_messages` and `workflow_store` stayed empty; the container's agent kept `null` stores and no thread |
| A failing `StringContains` | Exit code 1, sequential and with `--concurrency=2` |
| An evaluator constructor without `parent::__construct()` | Every item errored: `Typed property NeuronAI\Evaluation\BaseEvaluator::$ruleExecutor must not be accessed before initialization` |

Children end with `SIGKILL`, so objects left alive in a child are never destructed: the connection kept in `$inherited` is never closed there. The hooks handle Doctrine's connection; any other connection opened before the fork (a Redis client, a second DBAL connection) needs the same treatment.

## The plain `vendor/bin/neuron` route

`vendor/bin/neuron evaluation src/Neuron/Evaluators --autoload-file=bootstrap.php`, with a bootstrap that booted the kernel and an `evaluation.php` whose `resolver` called `$kernel->getContainer()->get($class)`, failed for every evaluator: `The "App\Neuron\Evaluators\SupportAgentEvaluator" service or alias has been removed or inlined when the container was compiled`. Evaluators are private services, and Neuron's own drivers (`ConsoleOutput`) are not services at all. It ran only after marking the evaluators `public: true`. The console command needs neither change, boots the kernel with the usual `--env` and `.env` handling, and adds the database-safe runner.

## CI

Rebuild the evaluation database, run `php bin/console neuron:evaluate --cache` and let its exit code fail the job; persist `var/evaluation/cache` between runs and schedule a `--fresh` run for provider drift (**neuron-evaluation**, "Caching in CI"). `--cache` replays `run()` until `run()`, its dataset item or a file in `cacheDependencies()` changes: every evaluator that receives the agent names the agent and each tool class there, or a changed prompt keeps replaying the old outputs.
