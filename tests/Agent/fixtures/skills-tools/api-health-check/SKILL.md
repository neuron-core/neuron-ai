---
name: api-health-check
description: Check if a given HTTP API is reachable and measure response time.
---

Check if a given HTTP API is reachable and measure response time.

## Tools
- name: api_health_check
  type: shell
  description: Check if a given HTTP API is reachable and measure response time.
  input_schema:
    url: string
    method: string
  execution:
    command: bash api_health_check.sh {{url}} {{method}}
    timeout: 30
    retry: 0
  output_schema:
    status_code: string
    response_time_ms: string
    response_preview: string
