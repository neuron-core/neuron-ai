#!/bin/bash

URL=$1
METHOD=${2:-GET}

if [ -z "$URL" ]; then
  echo "Usage: ./api_health_check.sh <url> [method]"
  exit 1
fi

START=$(date +%s%N)

if [ "$METHOD" = "POST" ]; then
  RESPONSE=$(curl -s -X POST "$URL")
  STATUS=$(curl -o /dev/null -s -w "%{http_code}" -X POST "$URL")
else
  RESPONSE=$(curl -s "$URL")
  STATUS=$(curl -o /dev/null -s -w "%{http_code}" "$URL")
fi

END=$(date +%s%N)

TIME=$(( (END - START) / 1000000 ))

echo "status_code=$STATUS"
echo "response_time_ms=${TIME}"
echo "response_preview=$(echo "$RESPONSE" | head -c 200)"
