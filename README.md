# NeuronAI: Create Full-Featured Agentic Applications in PHP

[![Latest Stable Version](https://poser.pugx.org/inspector-apm/neuron-ai/v/stable)](https://packagist.org/packages/inspector-apm/neuron-ai)
[![Total Downloads](http://poser.pugx.org/inspector-apm/neuron-ai/downloads)](https://packagist.org/packages/inspector-apm/neuron-ai)

> [!IMPORTANT]
> Get early access to new features, exclusive tutorials, and expert tips for building AI agents in PHP. Join a community of PHP developers pioneering the future of AI development.
> [Subscribe to the newsletter](https://neuron-ai.dev)

> Before moving on, support the community giving a GitHub star ⭐️. Thank you!

## What is NeuronAI?

NeuronAI is a PHP framework for creating and orchestrating AI Agents. It allows you to integrate AI entities in your existing PHP applications with a powerful and flexible architecture. We provide tools for the entire agentic application development lifecycle, from LLM interfaces, to data loading, to multi-agent orchestration, to monitoring and debugging.

## Table of Contents

- [Requirements](#requirements)
- [Official Documentation](#official-documentation)
- [Joomla 5 Extension: Agent Engine](#joomla-5-extension-agent-engine)
  - [Overview](#overview)
  - [Installation](#installation)
  - [Creating an Agent](#creating-an-agent)
  - [Using the Playground](#using-the-playground)
- [How To](#how-to)
- [Supported LLM Providers](#supported-llm-providers)
- [Tools & Toolkits](#tools--toolkits)
- [Security Vulnerabilities](#security-vulnerabilities)

## Requirements

- PHP: ^8.1

## Official documentation

**[Go to the official documentation](https://docs.neuron-ai.dev/)**

## Joomla 5 Extension: Agent Engine

### Overview

The `com_agentengine` extension provides a complete agent creation and deployment playground within your Joomla 5 administrator interface. It allows you to:

-   **Create and Manage Agents:** Define new AI agents by specifying their name, description, provider (OpenAI, Gemini, Ollama, etc.), model, and a system prompt.
-   **Assign Tools:** Equip your agents with tools to perform specific tasks.
-   **Interactive Playground:** Test and interact with your agents in a real-time chat interface.

### Installation

1.  **Package the Component:** Zip the contents of the `com_agentengine` directory into a file named `com_agentengine.zip`.
2.  **Install in Joomla:**
    *   Log in to your Joomla 5 administrator panel.
    *   Navigate to **System** -> **Install** -> **Extensions**.
    *   Upload the `com_agentengine.zip` file.

### Creating an Agent

1.  After installation, navigate to **Components** -> **Agent Engine** in the administrator menu.
2.  Click the **+ New** button to create a new agent.
3.  Fill out the form:
    *   **Name:** A descriptive name for your agent (e.g., "Customer Support Bot").
    *   **Description:** A brief explanation of what the agent does.
    *   **Provider:** Select the LLM provider (e.g., `OpenAI`, `Gemini`).
    *   **Model:** Specify the model name (e.g., `gpt-4o`, `gemini-pro`).
    *   **System Prompt:** Provide the base instructions that guide the agent's behavior.
    *   **Tools:** List the tools the agent can use, one per line.
4.  Click **Save & Close**.

### Using the Playground

1.  From the agent list, click the **Playground** button next to the agent you want to interact with.
2.  The playground view will open, featuring a chat interface.
3.  Type a message in the input box and press **Send**. The agent's response will appear in the chat history.

## How To

- [Install](#install)
- [Create an Agent](#create)
- [Talk to the Agent](#talk)
- [Monitoring](#monitoring)
- [Supported LLM Providers](#providers)
- [Tools & Toolkits](#tools)
- [MCP Connector](#mcp)
- [Structured Output](#structured)
- [RAG](#rag)
- [Workflow](#workflow)
- [Security Vulnerabilities](#security)

<a name="install">

## Install

Install the latest version of the package:

```
composer require neuron-core/neuron-ai
```
<a name="create">

## Create an Agent

Neuron provides you with the Agent class you can extend to inherit the main features of the framework
and create fully functional agents. This class automatically manages some advanced mechanisms for you, such as memory,
tools and function calls, up to the RAG systems. You can go deeper into these aspects in the [documentation](https://docs.neuron-ai.dev).

Let's create an Agent with the command below:

```
php vendor/bin/neuron make:agent DataAnalystAgent
```
<a name="talk">

## Talk to the Agent

Send a prompt to the agent to get a response from the underlying LLM:

```php

$agent = DataAnalystAgent::make();


$response = $agent->chat(
    new UserMessage("Hi, I'm Valerio. Who are you?")
);
echo $response->getContent();
// I'm a data analyst. How can I help you today?


$response = $agent->chat(
    new UserMessage("Do you remember my name?")
);
echo $response->getContent();
// Your name is Valerio, as you said in your introduction.
```

<a name="monitoring">

## Monitoring & Debugging

The best way to take your AI application under control is with [Inspector](https://inspector.dev). After you sign up,
make sure to set the `INSPECTOR_INGESTION_KEY` variable in the application environment file to start monitoring:

```dotenv
INSPECTOR_INGESTION_KEY=fwe45gtxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

<a name="providers">

## Supported LLM Providers

- [Anthropic](https://docs.neuron-ai.dev/components/ai-provider#anthropic)
- [OpenAI](https://docs.neuron-ai.dev/components/ai-provider#openai)
- [Ollama](https://docs.neuron-ai.dev/components/ai-provider#ollama)
- [Gemini](https://docs.neuron-ai.dev/components/ai-provider#gemini)
- [LM Studio](#)
- [LocalAI](#)
- [Mistral](https://docs.neuron-ai.dev/components/ai-provider#mistral)

<a name="tools">

## Tools & Toolkits

Make your agent able to perform concrete tasks, like reading from a database, by adding tools or toolkits (collections of tools).

<a name="security">

## Security Vulnerabilities
If you discover a security vulnerability within Neuron, please send an e-mail to the Inspector team via support@inspector.dev.
All security vulnerabilities will be promptly addressed.
