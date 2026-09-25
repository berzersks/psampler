# PCMAnalyzer measurement record, 2026-09-24

Hardware: Intel Core i5-13450HX, 6 P cores with SMT plus 4 E cores; kernel
7.0.0-31-generic, x86_64. The single core DSP benchmark used logical CPU 4
(P core 2). Two and four worker tests used CPUs 4,6 and 4,6,8,10, one thread
per physical P core. CPU scaling remained enabled (800–4600 MHz).

Versions: integration PHP 8.5.10 ZTS; optimized extension PHP 8.5.10 NTS;
Go 1.27.1 linux/amd64 with GOAMD64=v1; host GCC 13.3.0. `buildspc.sh`
used `-fstack-protector-strong -Os -g -fPIE -fPIC`, among other flags, and
`--no-strip --enable-zts`. The working tree's script did not use `--debug`.
Optimized extension: `phpize8.5`, `./configure
--with-php-config=/usr/bin/php-config8.5 --enable-psampler
CFLAGS='-O2 -Wall -Wextra -Wpedantic'`, `make -j4`. No `-march=native` was
used. Go: `go build -o /tmp/pcmgo bench/pcmgo/main.go`, no custom compiler
flags. `GO111MODULE=off go test ./bench/pcmgo -bench BenchmarkAnalyze
-benchmem -benchtime=1000x` measured Go allocation behavior.

Dataset: `php bench/generate-fixtures.php`, fixed seed, 95 PCM16LE mono files
(19 signal types × 1,3,5,10,15 seconds). `shadow-report.json` is the
fixture-level PHP/C/Go equivalence and result digest record. All 95 fixtures
had zero differences under tolerance 0.002 for rounded continuous features,
with exact signal, segment timing, acoustic class and policy decision.

Single core commands, each run separately after 25 warm-up jobs per length:

```
taskset -c 4 ./php bench/dsp-benchmark.php php 1000
taskset -c 4 ./php bench/dsp-benchmark.php native 1000
taskset -c 4 /usr/bin/php8.5 -d extension=/tmp/psampler-opt85/modules/psampler.so bench/dsp-benchmark.php php 1000
taskset -c 4 /usr/bin/php8.5 -d extension=/tmp/psampler-opt85/modules/psampler.so bench/dsp-benchmark.php native 1000
taskset -c 4 env GOMAXPROCS=1 /tmp/pcmgo -mode=bench -dir=bench/fixtures -runs=1000 -workers=1
```

The three `psampler-bench-e-core-*.jsonl` files repeat the integration C,
optimized C and Go commands above with `taskset -c 14`, an E core. They use
the same 95 PCM files and 1,000 jobs per duration.

The optimized PHP baseline uses the same interpreter as optimized C, so those
relative speeds are a like-for-like runtime comparison. The integration
binary is ZTS and statically linked. The optimized PHP comparison is NTS and
dynamically loads psampler. Treat the latter as an implementation benchmark,
not as a direct production latency prediction.

Concurrent commands:

```
python3 bench/concurrent-benchmark.py --workers 2 --runs-per-worker 500
python3 bench/concurrent-benchmark.py --workers 4 --runs-per-worker 250
```

The C side uses separate PHP worker processes; Go uses one process with
GOMAXPROCS equal to the worker count and one Analyzer per goroutine. Throughput
and CPU are measured across all workers. Peak RSS for C is the sum of worker
process peaks; Go's is the process high water mark.

SipSwoole stress commands, run separately using the full test PHP with
Swoole, bcg729, Opus, Redis, pgsql, posix and pcntl:

```
MAILBOX_STRESS_PHP_BINARY=/tmp/psampler-full-opus-php MAILBOX_ANALYZER_IMPLEMENTATION=php bash bench/mailbox-global-stress.sh
MAILBOX_STRESS_PHP_BINARY=/tmp/psampler-full-opus-php MAILBOX_ANALYZER_IMPLEMENTATION=native bash bench/mailbox-global-stress.sh
MAILBOX_ANALYZER_IMPLEMENTATION=php MAILBOX_STRESS_BURST=1 MAILBOX_STRESS_LEVELS=100,250,500 MAILBOX_STRESS_CALLS=200 /tmp/psampler-full-opus-php tests/MailboxConcurrentFinalizationStress.php
MAILBOX_ANALYZER_IMPLEMENTATION=native MAILBOX_STRESS_BURST=1 MAILBOX_STRESS_LEVELS=100,250,500 MAILBOX_STRESS_CALLS=200 /tmp/psampler-full-opus-php tests/MailboxConcurrentFinalizationStress.php
```

The full test PHP binary was produced by an extended SPC invocation. Its CLI
build completed and passed runtime checks, but SPC then returned failure while
collecting the Opus license because the downloaded Opus source had no license
file. This packaging failure does not affect the mandatory `buildspc.sh` build,
which completed. The full test binary is retained outside the repository at
`/tmp/psampler-full-opus-php`.

`ack-under-stress.json` records a separate E2E ACK probe launched one second
after each 200 RTP call stress run began. Reproduce a cell from the SipSwoole
repository with `impl=php` or `native` and `level=100`, `250` or `500`:

```
MAILBOX_ANALYZER_IMPLEMENTATION="$impl" MAILBOX_STRESS_BURST=1 MAILBOX_STRESS_LEVELS="$level" MAILBOX_STRESS_CALLS=200 /tmp/psampler-full-opus-php tests/MailboxConcurrentFinalizationStress.php > /tmp/mailbox-stress.jsonl 2>&1 &
stress_pid=$!
sleep 1
MAILBOX_ANALYZER_IMPLEMENTATION="$impl" MAILBOX_E2E_ACK_PROBE=1 MAILBOX_E2E_ACK_PROBE_CALLS=3 /tmp/psampler-full-opus-php tests/MailboxEndToEndTest.php > /tmp/mailbox-ack.log 2>&1
wait "$stress_pid"
```

The probe and RTP generators are separate processes. Three calls per cell
verify ACK delivery and ordering under host load, but cannot establish a
stable p95/p99 for the SIP server.
