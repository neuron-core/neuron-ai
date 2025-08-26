---
description: Learn how Neuron AI manage multi turn conversations.
---

# Chat History & Memory

Neuron AI has a built-in system to manage the memory of a chat session you perform with the agent.

In many Q\&A applications you can have a back-and-forth conversation with the LLM, meaning the application needs some sort of "memory" of past questions and answers, and some logic for incorporating those into its current thinking.

For example, if you ask a follow-up question like "Can you elaborate on the second point?", this cannot be understood without the context of the previous message. Therefore we can't effectively perform retrieval with a question like this.

In the example below you can see how the Agent doesn't know my name initially:

```php
use NeuronAI\Agent;
use NeuronAI\Chat\Messages\UserMessage;

$response = Agent::make()->chat(new UserMessage("What's my name?"));

echo $response->getContent();
// I'm sorry I don't know your name. Do you want to tell me more about yourself?
```

Clearly the Agent doesn't have any context about me. Now I try present me in the first message, and then ask for my name:

```php
use NeuronAI\Agent;
use NeuronAI\Chat\Messages\UserMessage;

$agent = Agent::make()

$response = $agent->chat(
    new UserMessage("Hi, my name is Valerio!")
);
echo $response->getContent();
// Hi Valerio, nice to meet you, how can I help you today?


$response = $agent->chat(
    new UserMessage("Do you remember my name?")
);
echo $response->getContent();
// Sure, your name is Valerio!
```

## How Chat History works

Neuron Agents take the list of messages exchanged between your application and the LLM into an object called Chat History. It's a crucial part of the framework because the chat history needs to be managed based on the context window of the underlying LLM.

It's important to send past messages back to LLM to keep the context of the conversation, but if the list of messages grows enough to exceed the context window of the model the request will be rejected by the AI provider.

Chat history automatically truncates the list of messages to never exceed the context window avoiding unexpected errors.

## How to feed a previous conversation

Sometimes you already have a representation of user to assistant conversation and you need a way to feed the agent with previous messages.

You just need to pass an array of messages to the \`chat()\` method. This conversation will be automatically loaded into the agent memory and you can continue to iterate on it.

```php
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;

$response = MyAgent::make()
    ->chat([
        new Message(MessageRole::USER, "Hi, my company is called Inspector.dev"),
        new Message(MessageRole::ASSISTANT, "Hi, how can I assist you today?"),
        new Message(MessageRole::USER, "What's the name of the company I work for?"),
    ]);
    
echo $response->getContent();
// You work for Inspector.dev
```

The last message in the list will be considered the most recent.

## How to register a chat history

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        ...
    }
    
    protected function chatHistory()
    {
        return new InMemoryChatHistory(
            contextWindow: 50000
        );
    }
}
```

[`InMemoryChatHistory`](chat-history-and-memory.md#inmemorychathistory) is used into the agent by default.  Check out below to learn more&#x20;

## Available Chat History Implementations

### InMemoryChatHistory

It simply store the list of messages into an array. It is kept in memory only during the current execution.&#x20;

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Providers\AIProviderInterface;

class MyAgent extends Agent
{
    ...
    
    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory(
            contextWindow: 50000
        );
    }
}
```

### FileChatHistory

This compnent makes you able  to persist the ongoing conversation with the agent in a file, and resume it later in time. To create an instance of the `FileChatHistory` you need to pass the absolute path of the `directory` where you want to store conversations, and the unique `key` for the current conversation.

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Providers\AIProviderInterface;

class MyAgent extends Agent
{
    ...
    
    protected function chatHistory(): ChatHistoryInterface
    {
        return new FileChatHistory(
            directory: '/home/app/storage/neuron',
            key: 'THREAD_ID',
            contextWindow: 50000
        );
    }
}
```

The `key` parameter allows you to store different files to separate conversations. You can use a unique key for each user, or the ID of a thread to make users able to store multiple conversations.

### SQLChatHistory

This component allows you to store the ongoing conversation into a SQL database. Before using this component you must create the table on your database to store messages. Here is the SQL script:

```sql
CREATE TABLE chat_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id VARCHAR(255) NOT NULL,
  messages LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 
  UNIQUE KEY uk_thread_id (thread_id),
  INDEX idx_thread_id (thread_id)
);
```

You can customize this table addind more columns eventually to add a relation to your users or similar use cases. You can also customize the table name passing your custom one when creating the instance.

To create an instance of the `SQLChatHistory` you need to pass the `thread_id` to separate different conversation threads, and the `PDO` connection to the database.

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Providers\AIProviderInterface;

class MyAgent extends Agent
{
    ...
    
    protected function chatHistory(): ChatHistoryInterface
    {
        return new SQLChatHistory(
            thread_id: 'THREAD_ID',
            pdo: new \PDO("mysql:host=localhost;dbname=DB_NAME;charset=utf8mb4", "DB_USER", "DB_PASS"),
            table: 'chat_hisotry',
            contextWindow: 50000
        );
    }
}
```

If your application is built on top of a framewrok you can easily get the PDO connection from the ORM. Here are is couple of examples in the context of Laravel or Symfony applications.

#### Laravel

```php
namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Providers\AIProviderInterface;

class MyAgent extends Agent
{
    ...
    
    protected function chatHistory(): ChatHistoryInterface
    {
        return new SQLChatHistory(
            thread_id: 'CHAT_THREAD_ID',
            pdo: \DB::connection()->getPdo(),
            table: 'chat_hisotry',
            contextWindow: 50000
        );
    }
}
```

#### Symfony

You can register your agent as a service with an instance of `Doctrine\DBAL\Connection` as a constructor dependency:

```php
namespace App\Neuron;

use Doctrine\DBAL\Connection;
use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Providers\AIProviderInterface;

class MyAgent extends Agent
{
    public function __construct(protected Connection $connection)
    {}
    
    protected function chatHistory(): ChatHistoryInterface
    {
        return new SQLChatHistory(
            thread_id: 'CHAT_THREAD_ID',
            pdo: $this->connection->getNativeConnection(),
            table: 'chat_hisotry',
            contextWindow: 50000
        );
    }
}
```

## How to implement a new chat history

To create a new implementation of the chat history you must implement the `AbstractChatHistory`. It allows you to inherit several behaviors for the internal history management, so you have just to implement a couple of methods to save messages into the external storage you want to use.

```php
abstract class AbstractChatHistory implements ChatHistoryInterface
{
    public function addMessage(Message $message): ChatHistoryInterface;

    /**
     * @return Message[]
     */
    public function getMessages(): array;

    public function getLastMessage(): Message|false;

    /**
     * @param Message[] $messages
     */
    public function setMessages(array $messages): ChatHistoryInterface;

    public function flushAll(): ChatHistoryInterface;

    public function calculateTotalUsage(): int;
}
```

The abstract class already implement some utility methods to calculate tokens usage based on the AI provider responses and automatically cut the conversation based on the size of the context window. You just have to focus on the interaction with the underlying storage to add and remove messages, or clear the entire history.

We strongly suggest to look at other implementations like `FileChatHistory` to understand how to create your own.

### Serialize/Deserialize Messages

When the ChatHistory needs to store a message it must be serialized. The same way, when the ChatHistory component is instantiated it should load all the previous messages from the underlying storage (database, cache, etc) and deserialize them to the original message type.&#x20;

To serialize/deserialize messages consistently the `AbstractChatHistory` provides you with `serializeMessage()` and `deserializeMessage()` methods. Here is an example of how to use them in an hypothetical database chat history implementation:

```php
<?php

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;

class DatabaseChatHistory extends AbstractChatHistory
{
    public function __construct(
        protected string $db,
        protected string $key,
    ) {
        // Retrieve the current conversation from the underlying storage
        $messages = $this->db->select(...);
        
        // Deserialize properly initialize the correct message types with the correct data.
        $this->history = $this->deserializeMessages($messages);
        
        // Or deserialize messages individually
        $this->history = \array_map(
            fn(array $message) => $this->deserializeMessage($message),
            $messages
        );
    }

    protected function storeMessage(Message $message): ChatHistoryInterface
    {
        // Store the serialized version.
        $this->db->insert($message->jsonSerialize());
        return $this;
    }

    ...
}
```

The serialization/deserialization process makes messages saveable in any type of storage.
