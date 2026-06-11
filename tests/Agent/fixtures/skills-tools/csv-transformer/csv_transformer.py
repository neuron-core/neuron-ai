#!/usr/bin/env python3

import csv
import sys

if len(sys.argv) < 3:
    print("Usage: python csv_transformer.py <input.csv> <output.csv>")
    sys.exit(1)

input_file = sys.argv[1]
output_file = sys.argv[2]

def clean(row):
    return [c.strip().lower() for c in row]

with open(input_file, "r", encoding="utf-8") as fin, \
     open(output_file, "w", encoding="utf-8", newline="") as fout:

    reader = csv.reader(fin)
    writer = csv.writer(fout)

    for row in reader:
        if not any(row):
            continue
        writer.writerow(clean(row))

print(f"done -> {output_file}")
