#!/usr/bin/env python3
import sys

if __name__ == '__main__':
    logfile = sys.argv[1] if len(sys.argv) > 1 else '-'
    if logfile == '-':
        print("Usage: log_error_analyzer.py <logfile>")
        sys.exit(1)
    errors = 0
    warns = 0
    try:
        with open(logfile) as f:
            for line in f:
                if 'ERROR' in line:
                    errors += 1
                elif 'WARN' in line:
                    warns += 1
    except FileNotFoundError:
        print(f"File not found: {logfile}")
        sys.exit(1)
    print(f"error_count= {errors}")
    print(f"warn_count= {warns}")
