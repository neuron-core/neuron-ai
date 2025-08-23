---
description: The key breakthrough is that interruption isn't a bug – it's a feature.
---

# Human In The Loop

Neuron Workflow supports a robust **human-in-the-loop** pattern, enabling human intervention at any point in an automated process. This is especially useful in large language model (LLM)-driven applications where model output may require validation, correction, or additional context to complete the task.

Here's how it works technically:

**Interruption Points**: Any node in your Workflow can request an interruption by specifying the data it want to present to the human. This could be a simple yes/no decision, a content review, data validation, or structured data.

**State Preservation**: When an interruption happens, NeuronAI automatically saves the complete state of your Workflow. Your Workflow essentially goes to sleep, waiting for human input.

**Resume Capability**: Once a human provides the requested input, the Workflow wakes up exactly from the node it left off. No data is lost, no context is forgotten.

**External Feedback Integration**: The human input becomes part of the Workflow's data, available to all subsequent nodes. This means later steps can make better decisions based on both AI analysis and human judgment.

### Interruption

When a NeuronAI Workflow encounters an interruption, it doesn't simply stop—it preserves its entire state, and waits for guidance before proceeding. This creates a hybrid intelligence system where AI handles the computational heavy lifting while humans contribute to strategic oversight, domain expertise, and decision-making.

You can ask for an interruption calling the `interrupt()` method inside a node:

```php
<?php

namespace App\Neuron\Workflow\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InterruptionNode extends Node
{
    public function run(WorkflowState $state): WorkflowState
    {
        // Interrupt the workflow and wait for the feedback.
        $feedback = $this->interrupt([
            'question' => 'Should we continue?',
            'current_value' => $state->get('accuracy')
        ]);
    
        if ($feedback['approved']) {
            $state->set('is_sufficient', true);
            $state->set('user_response', $feedback['response']);
            return $state;
        }
        
        $state->set('is_sufficient', false);
        return $state;
    }
}
```

Calling the `interrupt()` method you can pass the information you need to interact with the human. You will be able to catch this data later, outside of the workflow so you can inform the user with relevant information from inside the Workflow to ask for feedback.&#x20;

When the Workflow will be resumed it will restart from this node, and the `$feedback` variable will receive the human's response data.

{% hint style="info" %}
**Note**: The Workflow will restart the execution from the node where it was interrupted. The node will be re-executed including the code present before the interruption.
{% endhint %}

### Manage Interruption

To be able to interrupt and resume a Workflow you need to provide a persistence component, and a workflow ID when creating the Workflow instance:

```php
$workflow = new SimpleWorkflow(
    new FilePersistence(__DIR__),
    'CUSTOM_ID'
);
```

The `ID` is the reference to save and load the state of a specific Workflow during the interruption and resume process. The interruption request from a node will fire a special type of exception represented by the `WorkflowInterrupt` class. You can catch this exception to manage the interruption request.

```php
try {
    $workflow->run();
} catch (WorkflowInterrupt $interrupt) {
    $data = $interrupt->getData();
    
    /*
     * Store $data['question'], $data['current_value'] and the Workflow-ID,
     * and alert the user to provide a feedback.
     */
}
```

Use the information in the `$data` array to guide the human in providing a feedback. Once you finally have the user's feedback you can resume the workflow. Remeber to use the same `ID` of the interrupted execution.

```php
$workflow = new SimpleWorkflow(
    new FilePersistence(__DIR__),
    'CUSTOM_ID' // <- Use the same ID of the interrupted workflow
);

// Resume the Workflow passing the human feedback
$result = $workflow->resume(['approved' => true]);

// Get the final answer
echo $result->get('answer');
```

You can take a look at the script below as an example of this process:&#x20;

{% @github-files/github-code-block url="https://github.com/inspector-apm/neuron-ai/blob/main/examples/workflow/workflow-interrupt.php" %}
