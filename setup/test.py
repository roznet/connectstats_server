#!/usr/bin/env python3
"""Unified test runner for ConnectStats server.

Usage:
    python3 test.py full     # Destructive: reset DB, register, upload, validate
    python3 test.py smoke    # Non-destructive: API endpoint checks
    python3 test.py schema   # Non-destructive: DB schema validation
    python3 test.py all      # Run schema + smoke (non-destructive)
"""

import argparse
import sys

from connectstats_test.config import Config, default_config_path
from connectstats_test.client import ConnectStatsClient
from connectstats_test.runner import TestSuite, BOLD, RESET, GREEN, RED
from connectstats_test import test_full, test_smoke, test_schema


def main() -> int:
    parser = argparse.ArgumentParser(
        description="ConnectStats server test runner",
        formatter_class=argparse.RawTextHelpFormatter,
    )
    parser.add_argument(
        "mode",
        choices=["full", "smoke", "schema", "all"],
        help=(
            "full    Destructive: reset DB, register users, upload data, validate\n"
            "smoke   Non-destructive: API smoke tests against existing data\n"
            "schema  Non-destructive: validate DB schema integrity\n"
            "all     Run schema + smoke (non-destructive)"
        ),
    )
    parser.add_argument("--base-url", default="http://localhost/dev", help="Base URL (default: http://localhost/dev)")
    parser.add_argument("--config", default=default_config_path(), help="Path to config.php")
    parser.add_argument("-v", "--verbose", action="store_true", help="Verbose output")

    args = parser.parse_args()

    try:
        config = Config.from_php_config(args.config)
    except Exception as e:
        print(f"Failed to load config from {args.config}: {e}", file=sys.stderr)
        return 2

    client = ConnectStatsClient(config, args.base_url)
    all_passed = True

    if args.mode == "schema":
        suite = TestSuite("Schema Validation", verbose=args.verbose)
        test_schema.run(config, suite)
        all_passed = suite.summary()

    elif args.mode == "smoke":
        suite = TestSuite("Smoke Tests", verbose=args.verbose)
        test_smoke.run(client, suite)
        all_passed = suite.summary()

    elif args.mode == "full":
        suite = TestSuite("Full Integration Test", verbose=args.verbose)
        test_full.run(client, config, suite)
        all_passed = suite.summary()

    elif args.mode == "all":
        print(f"{BOLD}Running non-destructive tests...{RESET}")

        suite_schema = TestSuite("Schema Validation", verbose=args.verbose)
        test_schema.run(config, suite_schema)
        p1 = suite_schema.summary()

        suite_smoke = TestSuite("Smoke Tests", verbose=args.verbose)
        test_smoke.run(client, suite_smoke)
        p2 = suite_smoke.summary()

        all_passed = p1 and p2

    print()
    if all_passed:
        print(f"{GREEN}{BOLD}All tests passed.{RESET}")
    else:
        print(f"{RED}{BOLD}Some tests failed.{RESET}")

    return 0 if all_passed else 1


if __name__ == "__main__":
    sys.exit(main())
