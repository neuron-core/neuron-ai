# Skill Specification

This document defines the complete specification for Neuron AI skills — how to create, structure, and configure skills using the `SKILL.md` format.

## Overview

A skill is a self-contained capability directory containing a `SKILL.md` file. Skills are registered with the agent via `addSkillDirectory()` or `addSkillPaths()`. Each skill can provide:

- **Instructions** — behavioral guidance the LLM follows when the skill is active
- **Declarative tools** — shell, HTTP, PHP, queue, or MCP tools the skill provides
- **Activation hints** — metadata describing when to activate the skill
- **Execution guidance** — suggested plans, reasoning strategies, and policies as natural language

The LLM is the sole orchestrator: it decides when to activate skills, which tools to call, and how to combine capabilities. The runtime provides context and tools — it never forces execution phases or controls reasoning flow.

## Directory Structure

```
skills/
└── shipping-calculator/       # Must match skill name in frontmatter
    ├── SKILL.md               # Skill definition (required)
    └── scripts/               # Optional: bundled scripts
        └── calc_shipping.sh
```

The directory name must match the `name` field in the frontmatter and use `kebab-case` (lowercase letters, numbers, hyphens).

---

## SKILL.md Sections

### Frontmatter (required)

YAML frontmatter between `---` delimiters.

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Skill identifier, `kebab-case`. Must match directory name. |
| `description` | Yes | One-line description shown in activation hints. |
| `metadata.priority` | No | Execution priority (lower = higher priority). Default: `0`. |
| `metadata` | No | Additional metadata (e.g. `author`, `version`). Not used by the runtime. |
| `license` | No | License identifier (e.g. `MIT`, `Apache-2.0`). |
| `compatibility` | No | Compatibility requirements (e.g. `PHP 8.1+`). |
| `allowed-tools` | No | Space-separated list of tool names the skill is allowed to use. |

### Body Description (optional)

The text between frontmatter and the first `##` section is the skill's description. Shown to the LLM both before and after activation.

### `## Trigger` (optional)

Natural language description of when the agent should activate this skill. Shown in the initial system prompt to help the LLM decide when to activate.

```markdown
## Trigger
When users ask about shipping costs, delivery fees, or shipping estimates.
```

### `## Reasoning` (optional)

High-level strategy for the LLM to follow when the skill is active. The LLM reads this as guidance and applies it using its own reasoning.

```markdown
## Reasoning
Confirm destination and package details first, then select the best shipping method. If the user doesn't specify a shipping method, provide a comparison of all available options.
```

### `## Plan` (optional)

Suggested execution steps the LLM can follow. This is natural language guidance — the LLM decides whether and how to follow it.

```markdown
## Plan
1. collect: Gather destination and package info
2. calculate: Run shipping calculation [tool: shipping_calculator]
3. summarize: Compare shipping options and present results
```

The `[tool: name]` annotation hints to the LLM which tool to use for a step. The LLM is not forced to use any specific tool — it uses its own judgment.

### `## Policy` (optional)

Behavioral constraints the LLM should respect. This is natural language guidance, not runtime-enforced.

```markdown
## Policy
mode: sequential
require-diagnosis: true
max-tool-calls: 5
```

### `## Fallback` (optional)

Instructions for what to do if execution fails or produces unexpected results.

```markdown
## Fallback
If shipping data is unavailable, ask the user to provide shipping details manually.
```

### `## Tools` (optional)

Declares tools using a structured YAML format. See the [Tool Specification](#tool-specification) section below for the complete reference.

```yaml
## Tools
- name: shipping_calculator
  type: shell
  description: Calculate shipping cost based on destination and weight
  input_schema:
    country: string
    weight_kg: string
    method: string [standard, express, economy]
  execution:
    command: bash scripts/calc_shipping.sh {{country}} {{weight_kg}} {{method}}
    timeout: 30
  output_schema:
    cost_usd: number
    estimated_days: string
  policy:
    idempotent: true
    side_effect: false
    max_calls: 5
```

---

## Activation Model

### How Activation Works

Skills use an **LLM-initiated activation** model with a single agent loop:

1. **Bootstrap**: All registered skills are summarized in the system prompt with activation hints:
   ```
   # shipping-calculator: Calculate cross-border shipping costs
   When you need to use this skill, respond with [ACTIVATE_SKILL: shipping-calculator]
   ```

2. **LLM triggers activation**: When the LLM determines a skill is needed, it responds with:
   ```
   [ACTIVATE_SKILL: shipping-calculator]
   ```

3. **Runtime activates**: The runtime:
   - Records the activation
   - Adds the skill's tools to the available tool pool (parsed from `## Tools`, never shown as text)
   - Appends the skill's instructions as a context block. `## Tools` and `## Trigger` are excluded — Tools are parsed into Tool objects, Trigger is shown separately in the activation hints. Reasoning, Plan, Policy, and Fallback sections are included.

4. **Loop continues**: The LLM sees the new tools and instructions on the next turn. It decides which tools to call and how.

5. **Final response**: After using the tools, the LLM produces a final text response and the conversation ends.

### Multi-Skill Activation

Multiple skills can be activated in a single LLM response:

```
[ACTIVATE_SKILL: shipping-calculator]
[ACTIVATE_SKILL: get-weather]
```

When this happens:
- All requested skills are activated simultaneously
- Tools from all activated skills are exposed together
- Instructions from all skills are appended
- The LLM freely combines capabilities — it can call tools from different skills in any order

### Key Properties

- **Single loop**: There is only one agent loop. Activation does NOT create nested loops or secondary workflows.
- **Append-only**: Skill instructions are never removed once injected. The system prompt grows as skills are activated.
- **LLM-orchestrated**: The runtime does NOT schedule skills, force execution phases, or control reasoning. The LLM decides everything.

---

## Tool Specification

### Tool Schema

Each tool in the `## Tools` section is a YAML object:

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Tool identifier, `kebab-case` (e.g., `calculate-shipping`) |
| `type` | Yes | Executor type: `shell`, `http`, `php`, `queue`, `mcp` |
| `description` | Yes | Human-readable description (shown to the LLM) |
| `input_schema` | Yes | Typed input parameters |
| `execution` | Yes | Type-specific execution configuration |
| `output_schema` | No | Expected output structure |
| `policy` | No | Execution constraints |

### Tool Types

#### `shell` — Shell Command Execution

Runs a shell command via `proc_open`. Best for scripts, CLI tools, and data processing.

```yaml
- name: csv_transformer
  type: shell
  description: Clean CSV file by trimming spaces and removing empty rows
  input_schema:
    input_csv: string
    output_csv: string
  execution:
    command: python3 csv_transformer.py {{input_csv}} {{output_csv}}
    timeout: 30
    retry: 1
  output_schema:
    cleaned_csv: string
  policy:
    idempotent: true
    side_effect: false
    max_calls: 5
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `command` | string | required | Command template with `{{param}}` placeholders |
| `timeout` | integer | 30 | Max execution time in seconds |
| `retry` | integer | 0 | Number of retries on failure |

#### `http` — HTTP Request

Makes HTTP requests. Best for REST APIs, webhooks, and external services.

```yaml
- name: track_shipment
  type: http
  description: Track a shipment by tracking number
  input_schema:
    tracking_number: string
  execution:
    http_method: GET
    url: "https://api.shipping.com/track/{{tracking_number}}"
    timeout: 15
    retry: 1
    headers:
      Authorization: "Bearer {{api_token}}"
  output_schema:
    status: string
    location: string
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `url` | string | required | URL template with `{{param}}` placeholders |
| `http_method` | string | `GET` | HTTP method: GET, POST, PUT, PATCH, DELETE |
| `headers` | object | `{}` | HTTP headers (key-value pairs) |
| `body` | object | inputs | Request body for POST/PUT/PATCH (defaults to inputs) |
| `timeout` | integer | 30 | Request timeout in seconds |
| `retry` | integer | 0 | Retries on 5xx errors |

#### `php` — PHP Class Method

Invokes a PHP class method. Best for business logic, database queries, and internal services.

```yaml
- name: calculate_pricing
  type: php
  description: Calculate product pricing with discounts
  input_schema:
    product_id: string
    quantity: integer
    discount_code: string
  execution:
    class: App\Services\PricingService
    method: calculate
  output_schema:
    subtotal: number
    discount: number
    total: number
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `class` | string | required | Fully qualified PHP class name |
| `method` | string | `__invoke` | Method name to call |
| `constructor_args` | object | `{}` | Arguments passed to the class constructor |

The method receives a single `array $inputs` parameter containing all input values.

#### `queue` — Async Queue Job

Dispatches a job to a queue and polls for completion. Best for long-running tasks.

```yaml
- name: generate_report
  type: queue
  description: Generate a PDF report asynchronously
  input_schema:
    report_type: string
    date_range: string
  execution:
    queue: reports
    job_class: App\Jobs\GenerateReportJob
    poll_interval: 3
    timeout: 120
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `queue` | string | required | Queue name |
| `job_class` | string | required | Job class to dispatch |
| `poll_interval` | integer | 2 | Seconds between status checks |
| `timeout` | integer | 60 | Max wait time in seconds |

**Note:** The queue executor requires `dispatcher` and `poller` callables to be configured at runtime.

#### `mcp` — MCP Server Tool

Calls a tool on an MCP (Model Context Protocol) server. Best for external tool servers.

```yaml
- name: search_documents
  type: mcp
  description: Search documents using external MCP server
  input_schema:
    query: string
    limit: integer
  execution:
    server_url: "http://localhost:3001"
    tool_name: search
    transport: http
    timeout: 15
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `server_url` | string | required | MCP server URL |
| `tool_name` | string | tool name | Name of the tool on the MCP server |
| `transport` | string | `http` | Transport protocol: `http` or `stdio` |
| `timeout` | integer | 30 | Request timeout in seconds |

**Note:** The MCP executor requires a `client` callable to be configured at runtime.

### Input Schema

The `input_schema` maps parameter names to types:

```yaml
input_schema:
  name: string
  age: integer
  weight: number
  active: boolean
  tags: array
  metadata: object
```

All parameters are required by default. Optional parameters are not currently supported in the declarative format — pass empty/default values from the LLM.

**Enum values**: Append `[value1, value2, value3]` to the type to define allowed values:

```yaml
input_schema:
  method: string [standard, express, economy]
```

**Parameter descriptions**: Append `# description text` to provide a human-readable description shown to the LLM. This helps the model understand the expected input format:

```yaml
input_schema:
  city: string # City name in Chinese (e.g. 深圳、北京)
  method: string [standard, express, economy] # Shipping method
  weight_kg: number # Package weight in kilograms
```

When no `# description` is provided, the description is auto-generated from the parameter name (e.g. `weight_kg` → "Weight kg").

### Output Schema

The `output_schema` describes what the tool returns. This is included in the tool description shown to the LLM, helping it understand what to expect.

```yaml
output_schema:
  cost_usd: number
  estimated_days: string
  tracking_url: string
```

### Tool Policy

The `policy` section controls execution behavior:

```yaml
policy:
  idempotent: true           # Safe to call multiple times with same inputs
  side_effect: false         # Whether this tool causes side effects
  max_calls: 5               # Max times this tool can be called per session
  retry_on_failure: false    # Whether to automatically retry on failure
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `idempotent` | boolean | `false` | Safe to retry without side effects |
| `side_effect` | boolean | `true` | Whether the tool modifies external state |
| `max_calls` | integer | `0` | Max calls per session (0 = unlimited) |
| `retry_on_failure` | boolean | `false` | Auto-retry on execution failure |

### Multi-Tool Skills

A skill can define multiple tools:

```yaml
## Tools
- name: fetch_listing_metrics
  type: shell
  description: Fetch listing performance metrics
  input_schema:
    listing_id: string
    platform: string
  execution:
    command: python3 scripts/cli.py fetch-metrics --listing-id {{listing_id}} --platform {{platform}}

- name: optimize_listing_title
  type: shell
  description: Optimize listing title based on keyword trends
  input_schema:
    listing_id: string
    platform: string
  execution:
    command: python3 scripts/cli.py optimize-title --listing-id {{listing_id}} --platform {{platform}}

- name: check_seo_compliance
  type: http
  description: Check if listing meets platform SEO requirements
  input_schema:
    listing_id: string
    platform: string
  execution:
    http_method: GET
    url: "https://seo-checker.internal/api/check/{{listing_id}}?platform={{platform}}"
```

---

## Complete Example

```markdown
---
name: shipping-calculator
description: Calculate cross-border shipping costs and delivery estimates
metadata:
  priority: "2"
---

Calculate shipping costs based on destination, weight, and shipping method.

## Trigger
When users ask about shipping costs, delivery fees, or shipping estimates.

## Reasoning
Confirm destination and package details first, then select the best shipping method. If the user doesn't specify a shipping method, provide a comparison of all available options.

## Plan
1. collect: Collect destination country and package weight
2. calculate: Run shipping calculation [tool: shipping_calculator]
3. summarize: Summarize and compare shipping options

## Policy
mode: sequential
require-diagnosis: true
max-tool-calls: 5

## Fallback
If shipping data is unavailable, ask the user to provide shipping details manually.

## Tools
- name: shipping_calculator
  type: shell
  description: Calculate shipping cost based on destination and weight
  input_schema:
    country: string
    weight_kg: string
    method: string [standard, express, economy]
  execution:
    command: bash scripts/calc_shipping.sh {{country}} {{weight_kg}} {{method}}
    timeout: 30
    retry: 1
  output_schema:
    cost_usd: number
    estimated_days: string
  policy:
    idempotent: true
    side_effect: false
    max_calls: 5
```
