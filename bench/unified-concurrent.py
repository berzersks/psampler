#!/usr/bin/env python3
"""Measure the same unified DSP on 1, 2 or 4 distinct physical cores."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument("--workers", type=int, choices=(1, 2, 4), required=True)
parser.add_argument("--runs-per-worker", type=int, default=1000)
parser.add_argument("--php", default="/usr/bin/php8.4")
parser.add_argument("--extension", default="/tmp/psampler-unified-ext/modules/psampler.so")
parser.add_argument("--go", default="/tmp/pcmgo-unified")
parser.add_argument("--cpus", default="2,4,6,8", help="comma-separated distinct physical CPU IDs")
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
cpu_pool = [int(value) for value in args.cpus.split(",")]
if len(cpu_pool) < args.workers or len(set(cpu_pool[:args.workers])) != args.workers:
    raise ValueError("Provide one distinct CPU ID per worker")
cpus = cpu_pool[:args.workers]
with tempfile.TemporaryDirectory(prefix="psampler-unified-") as temporary:
    env = dict(os.environ, UNIFIED_BENCH_SAMPLES_DIR=temporary)
    processes = [subprocess.Popen(["taskset", "-c", str(cpu), args.php, "-n",
        "-d", "extension=" + args.extension, str(root / "bench/unified-benchmark.php"),
        str(args.runs_per_worker)], cwd=root, env=env, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE) for cpu in cpus]
    summaries = []
    for process in processes:
        output, error = process.communicate()
        if process.returncode:
            raise RuntimeError(error)
        summaries.append({row["duration_s"]: row for row in map(json.loads, output.splitlines())})
    for seconds in (1, 3, 5, 10, 15):
        samples = [json.loads(path.read_text()) for path in Path(temporary).glob(f"*-{seconds}.json")]
        if len(samples) != args.workers:
            raise RuntimeError("Missing worker samples")
        times = sorted(t for sample in samples for t in sample["times_ms"])
        start = min(sample["wall_start_ns"] for sample in samples)
        end = max(sample["wall_end_ns"] for sample in samples)
        cpu = sum(sample["cpu_ms"] for sample in samples)
        jobs = len(times)
        row = {"implementation": "c_zend", "workers": args.workers,
            "duration_s": seconds, "jobs": jobs,
            "p50_ms": times[(jobs + 1) // 2 - 1],
            "p95_ms": times[(jobs * 95 + 99) // 100 - 1],
            "p99_ms": times[(jobs * 99 + 99) // 100 - 1],
            "max_ms": times[-1], "cpu_ms_per_job": cpu / jobs,
            "jobs_s": jobs / ((end - start) / 1e9),
            "rss_peak_kb_sum": sum(summary[seconds]["rss_peak_kb"] for summary in summaries)}
        print(json.dumps(row), flush=True)
    go_env = dict(os.environ, GOMAXPROCS=str(args.workers))
    go = subprocess.run(["taskset", "-c", ",".join(map(str, cpus)), args.go,
        "-mode=bench", "-dir=" + str(root / "bench/ring-fixtures"),
        "-runs=" + str(args.workers * args.runs_per_worker),
        "-workers=" + str(args.workers)], cwd=root, env=go_env,
        text=True, capture_output=True, check=True)
    for line in go.stdout.splitlines():
        row = json.loads(line)
        row["implementation"] = "go"
        print(json.dumps(row), flush=True)
