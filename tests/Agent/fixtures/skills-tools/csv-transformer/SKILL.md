---
name: csv-transformer
description: Clean CSV file by trimming spaces, removing empty rows, and normalizing text.
---

## Tools
- name: csv_transformer
  type: shell
  description: Clean CSV file by trimming spaces, removing empty rows, and normalizing text.
  input_schema:
    input_csv: string
    output_csv: string
  execution:
    command: python3 csv_transformer.py {{input_csv}} {{output_csv}}
    timeout: 30
    retry: 0
  output_schema:
    cleaned_csv: string
