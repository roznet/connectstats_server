"""Test runner with result tracking and colored output."""

import time
from dataclasses import dataclass, field


# ANSI color codes
GREEN = "\033[92m"
RED = "\033[91m"
YELLOW = "\033[93m"
BOLD = "\033[1m"
RESET = "\033[0m"


@dataclass
class TestResult:
    name: str
    passed: bool
    message: str = ""
    duration_ms: float = 0.0


class TestSuite:
    """Collects test results and prints summary."""

    def __init__(self, name: str, verbose: bool = False):
        self.name = name
        self.verbose = verbose
        self.results: list[TestResult] = []
        self._current_start: float = 0.0

    def start_test(self, name: str) -> None:
        self._current_start = time.monotonic()
        if self.verbose:
            print(f"  Running: {name}...", end=" ", flush=True)

    def check(self, condition: bool, name: str, message: str = "") -> bool:
        """Record a test result. Returns the condition value."""
        elapsed = (time.monotonic() - self._current_start) * 1000 if self._current_start else 0.0
        result = TestResult(name=name, passed=condition, message=message, duration_ms=elapsed)
        self.results.append(result)

        if self.verbose:
            if condition:
                print(f"{GREEN}PASS{RESET}")
            else:
                print(f"{RED}FAIL{RESET}" + (f" - {message}" if message else ""))
        elif not condition:
            print(f"  {RED}FAIL{RESET}: {name}" + (f" - {message}" if message else ""))

        self._current_start = 0.0
        return condition

    def summary(self) -> bool:
        """Print results summary. Returns True if all passed."""
        passed = sum(1 for r in self.results if r.passed)
        failed = sum(1 for r in self.results if not r.passed)
        total = len(self.results)

        print()
        print(f"{BOLD}=== {self.name} ==={RESET}")

        if failed > 0:
            for r in self.results:
                if not r.passed:
                    print(f"  {RED}FAIL{RESET}: {r.name}" + (f" - {r.message}" if r.message else ""))

        color = GREEN if failed == 0 else RED
        print(f"  {color}{passed}/{total} passed{RESET}", end="")
        if failed > 0:
            print(f", {RED}{failed} failed{RESET}", end="")
        print()

        return failed == 0
