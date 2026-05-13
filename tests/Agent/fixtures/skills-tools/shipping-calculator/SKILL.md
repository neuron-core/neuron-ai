---
name: shipping-calculator
description: Calculate cross-border e-commerce shipping costs for different countries and logistics methods
metadata:
  priority: "2"
---

Calculate shipping cost and estimated delivery time based on destination country, package weight, and logistics method.

## Trigger
When the user asks about shipping costs, logistics fees, or shipping calculations.

## Reasoning
Confirm the destination and package details first, then select the appropriate logistics method. If the user does not specify a method, provide a comparison of all available options.


## Tools
- name: shipping_calculator
  type: shell
  description: Calculate shipping cost based on destination and weight
  input_schema:
    country: string # Destination country name in English
    weight_kg: string # Package weight in kilograms
    method: string [standard, express, economy] # Shipping method
  execution:
    command: bash scripts/calc_shipping.sh {{country}} {{weight_kg}} {{method}}
    timeout: 30
    retry: 0

- name: exchange_rate
  type: shell
  description: Convert USD amount to target currency using exchange rates
  input_schema:
    amount_usd: string # Amount in USD to convert
    target_currency: string [CNY, JPY, EUR, GBP, KRW, AUD] # Target currency code
  execution:
    command: bash scripts/calc_exchange.sh {{amount_usd}} {{target_currency}}
    timeout: 10
    retry: 0
