#!/usr/bin/env bash
set -euo pipefail
# Arguments: PHP executable, run-tests.php, shared psampler, alternate fixture.
# No real Swoole may be compiled into this PHP or loaded by its configuration.
if [ "$#" -ne 4 ]; then
  echo 'usage: bash tests/run-swoole-abi-matrix.sh PHP RUN_TESTS PSAMPLER_SO FIXTURE_SO' >&2
  exit 2
fi
php_bin=$1
runner=$2
psampler_so=$3
fixture_so=$4
repo_dir=$(cd -- "$(dirname -- "$0")/.." && pwd)
for mode in version table-version table-size null-table null-class foreign-class null-acquire null-release valid; do
  if [ "$mode" = valid ]; then
    test_file=byte_buffer_native_swoole_abi_fixture.phpt
  else
    test_file=byte_buffer_native_swoole_abi_rejected.phpt
  fi
  PSAMPLER_SWOOLE_TEST_ABI_MODE=$mode TEST_PHP_EXECUTABLE=$php_bin REPORT_EXIT_STATUS=1 \
    "$php_bin" -n "$runner" -n -d "extension=$psampler_so" -d "extension=$fixture_so" \
    "$repo_dir/tests/$test_file"
done
