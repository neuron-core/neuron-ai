---
name: get-weather
description: Weather query tool. Use this skill when users need to check real-time weather, temperature, humidity, or living index.
---

Query weather by city name.

## Trigger
When the user asks about weather or temperature conditions.

## Tools
- name: query_weather
  type: shell
  description: Query real-time weather by city name.
  input_schema:
    city: string #City Name (in Chinese) (e.g. 深圳、北京)
    adcode: string
    extended: string
    forecast: string
    indices: string
  execution:
    command: python3 scripts/cli.py query-weather --city {{city}}
    timeout: 30
    retry: 0
