#!/usr/bin/env python3
"""Run C PHP workers and one Go process on the same logical CPUs."""
import argparse
import json
import os
import pathlib
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--workers', type=int, choices=(1, 2, 4), required=True)
parser.add_argument('--runs-per-worker', type=int, default=200)
parser.add_argument('--first-cpu', type=int, default=4)
parser.add_argument('--cpu-stride', type=int, default=2)
parser.add_argument('--php', default='/usr/bin/php8.5')
parser.add_argument('--extension', default='/tmp/psampler-opt85/modules/psampler.so')
parser.add_argument('--go', default='/tmp/pcmgo')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
cpu_list = [args.first_cpu + worker * args.cpu_stride for worker in range(args.workers)]
cpus = ','.join(str(n) for n in cpu_list)
with tempfile.TemporaryDirectory(prefix='psampler-concurrent-') as sample_dir:
    env = os.environ.copy()
    env['DSP_BENCH_SAMPLES_DIR'] = sample_dir
    processes = [subprocess.Popen(['taskset', '-c', str(cpu_list[worker]),
        args.php, '-d', 'extension=' + args.extension,
        str(root / 'bench/dsp-benchmark.php'), 'native', str(args.runs_per_worker)],
        cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        for worker in range(args.workers)]
    summaries = []
    for process in processes:
        stdout, stderr = process.communicate()
        if process.returncode:
            raise RuntimeError(f'C worker failed: {stderr}')
        summaries.append([json.loads(line) for line in stdout.splitlines()])
    for seconds in (1, 3, 5, 10, 15):
        samples = [json.loads(path.read_text()) for path in pathlib.Path(sample_dir).glob(f'*-{seconds}.json')]
        if len(samples) != args.workers:
            raise RuntimeError('Missing C worker samples')
        times = sorted(time for sample in samples for time in sample['times_ms'])
        start = min(sample['wall_start_ns'] for sample in samples)
        end = max(sample['wall_end_ns'] for sample in samples)
        cpu = sum(sample['cpu_ms'] for sample in samples)
        jobs = len(times)
        rss = sum(row[seconds_index]['rss_peak_kb'] for row in summaries
            for seconds_index in range(len(row)) if row[seconds_index]['duration_s'] == seconds)
        print(json.dumps({'implementation': 'c_optimized', 'duration_s': seconds,
            'workers': args.workers, 'jobs': jobs, 'jobs_s': jobs / ((end - start) / 1e9),
            'cpu_total_pct': 100 * cpu / ((end - start) / 1e6),
            'cpu_per_core_pct': 100 * cpu / ((end - start) / 1e6) / args.workers,
            'cpu_ms_per_job': cpu / jobs, 'p50_ms': times[(jobs + 1) // 2 - 1],
            'p95_ms': times[int((jobs * 0.95 + 0.999999)) - 1],
            'p99_ms': times[int((jobs * 0.99 + 0.999999)) - 1],
            'rss_peak_kb_sum': rss}))
    go = subprocess.run(['taskset', '-c', cpus, args.go, '-mode=bench',
        '-dir=' + str(root / 'bench/fixtures'), '-runs=' + str(args.runs_per_worker * args.workers),
        '-workers=' + str(args.workers)], cwd=root, env={**os.environ, 'GOMAXPROCS': str(args.workers)},
        capture_output=True, text=True, check=True)
    for line in go.stdout.splitlines():
        row = json.loads(line)
        row['implementation'] = 'go_optimized'
        print(json.dumps(row))
