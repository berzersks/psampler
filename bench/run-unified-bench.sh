#!/usr/bin/env bash
set -euo pipefail

PSAMPLER_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BENCH_TMP="${BENCH_TMP:-/tmp/psampler-unified-repro}"
RESULTS_DIR="${RESULTS_DIR:-$PSAMPLER_ROOT/bench/results/local}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
PHPIZE_BIN="${PHPIZE_BIN:-phpize8.4}"
CPU_INDEX="${CPU_INDEX:-2}"
CONCURRENT_CPUS="${CONCURRENT_CPUS:-2,4,6,8}"
RUNS="${RUNS:-1000}"
BASELINE_COMMIT=6d8c79d047cbf958708613ef01a34756fcdd742e
mkdir -p "$BENCH_TMP" "$RESULTS_DIR"
cd "$PSAMPLER_ROOT"

python3 bench/generate-ring-fixtures.py
GO111MODULE=off go build -o "$BENCH_TMP/pcmgo" ./bench/pcmgo

source_files=(config.m4 psampler.c pcm_analyzer.c pcm_analyzer.h byte_buffer.c byte_buffer.h php_psampler.h)
for variant in old unified separate o3; do
    mkdir -p "$BENCH_TMP/$variant"
    for source_file in "${source_files[@]}"; do
        if [[ "$variant" == old ]]; then
            git show "$BASELINE_COMMIT:$source_file" > "$BENCH_TMP/$variant/$source_file"
        else
            cp "$source_file" "$BENCH_TMP/$variant/$source_file"
        fi
    done
    flags='-O2 -fno-fast-math -mtune=generic'
    if [[ "$variant" == separate ]]; then flags="$flags -DPCM_RING_SEPARATE"; fi
    if [[ "$variant" == o3 ]]; then flags='-O3 -fno-fast-math -mtune=generic'; fi
    (
        cd "$BENCH_TMP/$variant"
        "$PHPIZE_BIN" > phpize.log 2>&1
        CFLAGS="$flags" ./configure --enable-psampler > configure.log 2>&1
        make -j4 > make.log 2>&1
    )
done

mkdir -p "$BENCH_TMP/old/bench"
cp bench/unified-core.c "$BENCH_TMP/old/bench/unified-core.c"
gcc -O3 -fno-fast-math -mtune=generic bench/unified-core.c pcm_analyzer.c -lm -o "$BENCH_TMP/core-o3"
gcc -O3 -fno-fast-math -mtune=generic -DOLD_CORE \
    "$BENCH_TMP/old/bench/unified-core.c" "$BENCH_TMP/old/pcm_analyzer.c" -lm -o "$BENCH_TMP/core-old"

"$PHP_BIN" -n -d "extension=$BENCH_TMP/o3/modules/psampler.so" \
    bench/ring-compare.php "$BENCH_TMP/pcmgo" > "$RESULTS_DIR/ring-comparison.json"
"$PHP_BIN" -n -d "extension=$BENCH_TMP/o3/modules/psampler.so" \
    bench/ring-ground-truth.php > "$RESULTS_DIR/ring-ground-truth.json"
"$PHP_BIN" -n -d "extension=$BENCH_TMP/o3/modules/psampler.so" \
    bench/unified-compare.php "$BENCH_TMP/pcmgo" > "$RESULTS_DIR/c-go-comparison.json"

for variant in old unified separate o3; do
    taskset -c "$CPU_INDEX" "$PHP_BIN" -n \
        -d "extension=$BENCH_TMP/$variant/modules/psampler.so" \
        bench/unified-benchmark.php "$RUNS" > "$RESULTS_DIR/$variant-zend.jsonl"
done
taskset -c "$CPU_INDEX" "$BENCH_TMP/core-old" "$RUNS" > "$RESULTS_DIR/old-core.jsonl"
taskset -c "$CPU_INDEX" "$BENCH_TMP/core-o3" "$RUNS" > "$RESULTS_DIR/o3-core.jsonl"
GOMAXPROCS=1 taskset -c "$CPU_INDEX" "$BENCH_TMP/pcmgo" \
    -mode=bench -dir=bench/ring-fixtures -runs="$RUNS" -workers=1 > "$RESULTS_DIR/go.jsonl"

for workers in 2 4; do
    python3 bench/unified-concurrent.py --workers="$workers" \
        --runs-per-worker="$RUNS" --php="$PHP_BIN" \
        --extension="$BENCH_TMP/o3/modules/psampler.so" \
        --go="$BENCH_TMP/pcmgo" --cpus="$CONCURRENT_CPUS" \
        > "$RESULTS_DIR/concurrent-$workers.jsonl"
done
echo "Results: $RESULTS_DIR"
