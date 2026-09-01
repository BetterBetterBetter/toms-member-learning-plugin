#!/usr/bin/env bash
#
# Run every tests/*.php contract via wp eval-file against a live WordPress.
# The plugin's "tests" are WP-CLI contract scripts, not PHPUnit — this loops
# them so the suite can run as a whole (per-brand: point --path at the site).
#
# Usage:
#   tools/run-contract-tests.sh --path=/abs/path/to/wordpress [--only=<glob>]
#
# Exit non-zero if any contract fails. Runs one at a time (a shared local DB
# makes concurrent DB-mutating contracts unsafe).

set -uo pipefail

WP_PATH=""
ONLY="*"
for arg in "$@"; do
  case "$arg" in
    --path=*) WP_PATH="${arg#--path=}" ;;
    --only=*) ONLY="${arg#--only=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$WP_PATH" ]]; then
  echo "error: --path=/abs/path/to/wordpress is required" >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$SCRIPT_DIR/../tests"

pass=0; fail=0; skip=0; failed_names=()
for f in "$TESTS_DIR"/$ONLY.php; do
  [[ -e "$f" ]] || continue
  name="$(basename "$f")"
  # Use the EXIT CODE as the authoritative signal: WP_CLI::success exits 0,
  # WP_CLI::error exits non-zero. Grepping stdout/stderr is unreliable because
  # WP_DEBUG floods the streams and can bury/split the "Success:" line.
  out="$(wp --path="$WP_PATH" eval-file "$f" --skip-themes 2>&1)"
  rc=$?
  if (( rc == 0 )); then
    if echo "$out" | grep -q "Skipped:"; then
      echo "SKIP  $name"
      skip=$((skip+1))
    else
      echo "PASS  $name"
      pass=$((pass+1))
    fi
  else
    echo "FAIL  $name"
    echo "$out" | grep -iE "Error|Fatal|failed" | head -3 | sed 's/^/        /'
    fail=$((fail+1))
    failed_names+=("$name")
  fi
done

echo "----"
echo "passed: $pass  skipped: $skip  failed: $fail"
if (( fail > 0 )); then
  printf '  - %s\n' "${failed_names[@]}"
  exit 1
fi
