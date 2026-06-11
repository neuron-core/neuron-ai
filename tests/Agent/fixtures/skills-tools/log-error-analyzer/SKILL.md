---
name: log-error-analyzer
description: Analyze log files and count ERROR/WARN occurrences.
---

## Tools
- name: log_error_analyzer
  type: shell
  description: Analyze log files and count ERROR/WARN occurrences.
  input_schema:
    log_file: string
  execution:
    command: python3 log_error_analyzer.py {{log_file}}
    timeout: 30
    retry: 0
  output_schema:
    error_count: string
    warn_count: string
    top_errors: string
