#!/usr/bin/env bash
#
# Run every smoke test. Each test exits non-zero on failure, so this stops at
# the first broken one unless --all is passed.
#
#   bin/run-tests.sh
#   bin/run-tests.sh --all     # keep going, report the tally at the end
#
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KEEP_GOING="${1:-}"
FAILED=0

for test in "$ROOT"/tests/smoke-*.php; do
	echo "== $(basename "$test")"
	if ! php "$test"; then
		FAILED=$((FAILED + 1))
		if [ "$KEEP_GOING" != "--all" ]; then
			echo "FAILED: $test"
			exit 1
		fi
	fi
	echo
done

if [ "$FAILED" -gt 0 ]; then
	echo "$FAILED test file(s) failed."
	exit 1
fi

echo "All smoke tests passed."
