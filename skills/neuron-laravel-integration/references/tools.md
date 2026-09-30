# The order tools

`SupportAgent::tools()` returns these two tools. The tests, datasets and evaluators rely on their names (`lookup_order`, `refund_order`), their integer `order_id` argument and their output. `vendor/bin/neuron make:tool 'App\Neuron\Tools\LookupOrder'` names the tool after the class (`LookupOrder`) with a string `input` property: replace the generated body.

```php
namespace App\Neuron\Tools;

use App\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

class LookupOrder extends Tool
{
    protected string $name = 'lookup_order';

    protected ?string $description = 'Read the status and total of one of the customer\'s orders.';

    public function __construct(protected int $customerId)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_id',
                type: PropertyType::INTEGER,
                description: 'The order number',
                required: true,
            ),
        ];
    }

    public function __invoke(int $order_id): ToolOutput
    {
        $order = Order::query()->where('user_id', $this->customerId)->find($order_id);

        return $order === null
            ? ToolOutput::error("Order {$order_id} does not exist.")
            : ToolOutput::text("Order {$order->id} is {$order->status}, total {$order->total} EUR.");
    }
}
```

```php
namespace App\Neuron\Tools;

use App\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

class RefundOrder extends Tool
{
    protected string $name = 'refund_order';

    protected ?string $description = 'Refund one of the customer\'s orders.';

    public function __construct(protected int $customerId)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_id',
                type: PropertyType::INTEGER,
                description: 'The order number',
                required: true,
            ),
        ];
    }

    protected function approvalPolicy(): bool|string
    {
        return 'Refunds move money back to the customer.';
    }

    public function __invoke(int $order_id): ToolOutput
    {
        $refunded = Order::query()
            ->where('user_id', $this->customerId)
            ->whereKey($order_id)
            ->update(['status' => 'refunded']);

        return $refunded === 1
            ? ToolOutput::text("Order {$order_id} refunded.")
            : ToolOutput::error("Order {$order_id} does not exist.");
    }
}
```

- **The customer comes from the constructor**, which `SupportAgent::tools()` fills from the thread ID. It never comes from `auth()`, which is empty in a queue worker. Every query filters on that customer, so another customer's order "does not exist".
- **`approvalPolicy()`** returning a string makes every `refund_order` call wait for a human decision. The string is the reason the approver sees (`approvals.0.reason` in the JSON turn). Approval semantics: **neuron-tool-approval**.
- **`ToolOutput::error()`** hands the failure to the model as the tool result, and the turn goes on. An exception thrown from `__invoke()` fails the run.
- **The output names the currency**: the dataset expects `40 EUR`, so `total` is an integer column. A `decimal` column reads `40.00` on MySQL.

The model is a plain Eloquent model on an `orders` table (`id`, `user_id`, `status`, integer `total`, timestamps):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'status', 'total'])]
class Order extends Model
{
}
```

Writing tools, toolkits and their dependencies: **neuron-tool**.
