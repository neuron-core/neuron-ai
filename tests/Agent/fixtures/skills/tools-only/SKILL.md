---
name: tools-only
description: A skill with only a Tools section and nothing else
---

## Tools
- name: ping
  type: shell
  description: Ping a host
  input_schema:
    host: string
  execution:
    command: ping -c 1 {{host}}
    timeout: 10
    retry: 0
