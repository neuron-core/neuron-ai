# Neuron Cloud — Backend Implementation Spec

This document specifies the API and database schema for the Neuron Cloud platform backend. The backend receives tracing and evaluation data from the Neuron AI PHP SDK's `Cloud` module.

## Tech Stack

- Laravel 11+
- PostgreSQL 16+
- Queue jobs for async processing

## Authentication

All endpoints require a Bearer token:

```
Authorization: Bearer ncloud_xxxxx
```

API keys map 1:1 to a `projects` row. Look up the project by hashing the incoming key.

## API Endpoints

### `POST /api/traces`

Accept a trace with its spans.

**Expected response:** `202 Accepted`

```json
{ "trace_id": "trace_123", "stored": true }
```

**Request body:**

```json
{
    "trace_id": "trace_7281394710293847102938",
    "workflow": "App\\Agents\\MyAgent",
    "spans": [
        {
            "span_id": "span_7281394710293847102939",
            "parent_span_id": null,
            "name": "App\\Agents\\MyAgent",
            "kind": "INTERNAL",
            "start_time_unix_nano": 1717574400000000000,
            "end_time_unix_nano": 1717574402500000000,
            "status": "ok",
            "attributes": {
                "neuron.workflow.class": "App\\Agents\\MyAgent"
            }
        },
        {
            "span_id": "span_7281394710293847102940",
            "parent_span_id": "span_7281394710293847102939",
            "name": "inference(UserMessage)",
            "kind": "CLIENT",
            "start_time_unix_nano": 1717574400010000000,
            "end_time_unix_nano": 1717574401200000000,
            "status": "ok",
            "attributes": {
                "neuron.inference.input_role": "user",
                "neuron.inference.output_role": "assistant",
                "neuron.inference.output_content": "The weather is sunny.",
                "neuron.inference.usage.input_tokens": 24,
                "neuron.inference.usage.output_tokens": 12
            }
        },
        {
            "span_id": "span_7281394710293847102941",
            "parent_span_id": "span_7281394710293847102939",
            "name": "tool_call(get_weather)",
            "kind": "INTERNAL",
            "start_time_unix_nano": 1717574401300000000,
            "end_time_unix_nano": 1717574401500000000,
            "status": "ok",
            "attributes": {
                "neuron.tool.name": "get_weather",
                "neuron.tool.inputs": {"city": "Rome"},
                "neuron.tool.result": "Sunny, 22C"
            }
        }
    ]
}
```

**Trace fields:**

| Field | Type | Description |
|---|---|---|
| `trace_id` | `string` | Client-generated unique ID |
| `workflow` | `string` | Agent/workflow FQCN |
| `spans` | `array` | Ordered list of spans |

**Span fields:**

| Field | Type | Required | Description |
|---|---|---|---|
| `span_id` | `string` | Yes | Unique span ID |
| `parent_span_id` | `string\|null` | Yes | Parent span. `null` = root span |
| `name` | `string` | Yes | Human-readable name |
| `kind` | `string` | Yes | `INTERNAL` (local work), `CLIENT` (outbound LLM call), `SERVER` |
| `start_time_unix_nano` | `int` | Yes | Start timestamp (nanoseconds) |
| `end_time_unix_nano` | `int` | Yes | End timestamp (nanoseconds) |
| `status` | `string` | Yes | `ok` or `error` |
| `attributes` | `object` | No | Key-value context data |

**Attribute key conventions:**

| Key | Span type | Description |
|---|---|---|
| `neuron.workflow.class` | root | Agent FQCN |
| `neuron.inference.input_role` | inference | Input message role |
| `neuron.inference.output_role` | inference | Response message role |
| `neuron.inference.output_content` | inference | Response text content |
| `neuron.inference.usage.input_tokens` | inference | Token count in |
| `neuron.inference.usage.output_tokens` | inference | Token count out |
| `neuron.tool.name` | tool | Tool name |
| `neuron.tool.inputs` | tool | Tool input arguments (object) |
| `neuron.tool.result` | tool | Tool execution result |
| `neuron.node.class` | node | Node FQCN |
| `neuron.error.message` | error | Exception message |
| `neuron.error.class` | error | Exception FQCN |

---

### `POST /api/evaluations`

Accept an evaluation summary.

**Expected response:** `202 Accepted`

```json
{ "evaluation_id": 123, "stored": true }
```

**Request body:**

```json
{
    "timestamp": 1717574400000,
    "summary": {
        "total": 3,
        "passed": 2,
        "failed": 1,
        "success_rate": 0.6667,
        "total_execution_time": 4.521,
        "average_execution_time": 1.507,
        "total_assertions": 9,
        "assertions_passed": 8,
        "assertions_failed": 1,
        "assertion_success_rate": 0.8889,
        "has_failures": true
    },
    "results": [
        {
            "index": 0,
            "passed": true,
            "input": {"prompt": "What is 2+2?"},
            "output": "4",
            "execution_time": 1.2,
            "error": null,
            "assertions_passed": 3,
            "assertions_failed": 0,
            "assertion_scores": []
        },
        {
            "index": 1,
            "passed": false,
            "input": {"prompt": "Explain quantum computing"},
            "output": "Quantum computing uses qubits...",
            "execution_time": 2.1,
            "error": null,
            "assertions_passed": 2,
            "assertions_failed": 1,
            "assertion_scores": [0.7, 0.4]
        }
    ]
}
```

**Evaluation fields:**

| Field | Type | Description |
|---|---|---|
| `timestamp` | `int` | Unix timestamp in milliseconds |
| `summary` | `object` | Aggregate statistics |
| `results` | `array` | Per-test-case results |

---

### Error Responses

All errors return JSON:

```json
{ "error": "Unauthorized", "message": "Invalid API key" }
```

| Status | Meaning |
|---|---|
| `401` | Invalid or missing API key |
| `422` | Malformed payload |
| `429` | Rate limited (include `Retry-After` header) |
| `500` | Server error |

---

## Database Schema

### `projects`

```sql
CREATE TABLE projects (
    id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name       VARCHAR(255) NOT NULL,
    api_key    VARCHAR(255) NOT NULL UNIQUE,  -- hashed API key
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### `traces`

```sql
CREATE TABLE traces (
    id           BIGSERIAL PRIMARY KEY,
    project_id   UUID NOT NULL REFERENCES projects(id),
    trace_id     VARCHAR(255) NOT NULL,
    workflow     VARCHAR(255) NOT NULL,
    status       VARCHAR(50) NOT NULL DEFAULT 'ok',  -- derived from root span
    duration_ms  INTEGER,  -- computed from root span (end - start) / 1_000_000
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (project_id, trace_id)
);
```

### `spans`

```sql
CREATE TABLE spans (
    id                   BIGSERIAL PRIMARY KEY,
    trace_id             BIGINT NOT NULL REFERENCES traces(id) ON DELETE CASCADE,
    span_id              VARCHAR(255) NOT NULL,
    parent_span_id       VARCHAR(255),
    name                 VARCHAR(255) NOT NULL,
    kind                 VARCHAR(50) NOT NULL,  -- INTERNAL, CLIENT, SERVER
    start_time_unix_nano BIGINT NOT NULL,
    end_time_unix_nano   BIGINT NOT NULL,
    status               VARCHAR(50) NOT NULL DEFAULT 'ok',
    attributes           JSONB,
    created_at           TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_spans_trace_parent ON spans (trace_id, parent_span_id);
CREATE INDEX idx_spans_kind ON spans (kind);
```

### `evaluations`

```sql
CREATE TABLE evaluations (
    id                   BIGSERIAL PRIMARY KEY,
    project_id           UUID NOT NULL REFERENCES projects(id),
    timestamp            BIGINT NOT NULL,  -- client timestamp in ms
    total                INTEGER NOT NULL,
    passed               INTEGER NOT NULL,
    failed               INTEGER NOT NULL,
    success_rate         FLOAT NOT NULL,
    total_execution_time FLOAT NOT NULL,
    has_failures         BOOLEAN NOT NULL,
    summary_json         JSONB NOT NULL,  -- full summary object
    results_json         JSONB NOT NULL,  -- full results array
    created_at           TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_evaluations_project ON evaluations (project_id);
```

---

## Processing Logic

### Trace ingestion

1. Authenticate via API key → resolve `project_id`
2. Insert `traces` row with `trace_id`, `workflow`, `status` (from root span), `duration_ms`
3. Bulk insert `spans` rows
4. Return `202 Accepted` immediately; processing can be deferred to a queue job

### Evaluation ingestion

1. Authenticate via API key → resolve `project_id`
2. Insert `evaluations` row with summary + results as JSONB
3. Return `202 Accepted` immediately

---

## Rate Limiting

- 100 requests/minute per API key (configurable)
- Return `429` with `Retry-After` header

## Payload Size

- Maximum 10MB per request
- Reject with `422` if exceeded
