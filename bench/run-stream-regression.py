#!/usr/bin/env python3
"""Run the existing PHP benchmark with the official buildspc.sh binary."""
import argparse
import json
import statistics
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASELINE = ROOT / 'bench/results/pcm-standalone-2026-10-02/matrix.json'
PAIRS = [(8000,8000),(16000,8000),(24000,8000),(32000,8000),
         (44100,8000),(48000,8000),(96000,8000),
         (8000,16000),(8000,32000),(8000,44100),(8000,48000),
         (44100,48000),(48000,44100)]

def parse(output):
    result = {}
    for line in output.splitlines():
        if ': ' in line:
            key, value = line.split(': ', 1)
            result[key] = value
    if result.get('validation') != 'ok':
        raise RuntimeError(output)
    return result

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default=str(ROOT / 'php'))
    parser.add_argument('--calls', type=int, default=50)
    parser.add_argument('--frames', type=int, default=10000)
    parser.add_argument('--output', default=str(ROOT / 'bench/results/pcm-stream-2026-10-02/benchmark.json'))
    parser.add_argument('--rates', nargs='*', type=int)
    args = parser.parse_args()
    php = str(Path(args.php).resolve())
    baseline = json.loads(BASELINE.read_text())
    results = []
    for src, dst in PAIRS:
        if args.rates and src not in args.rates:
            continue
        command = ['taskset','-c','2',php,'-n',str(ROOT / 'pcm_benchmark.php'),
                   '--runtime=throughput',f'--calls={args.calls}',f'--frames={args.frames}',
                   '--ptime=20',f'--source-rate={src}','--source-channels=2',
                   f'--target-rate={dst}','--target-channels=1']
        run = subprocess.run(command, cwd=ROOT, text=True, capture_output=True)
        if run.returncode:
            raise RuntimeError(f'{src}->{dst}: {run.stderr}\n{run.stdout}')
        record = parse(run.stdout)
        old = None
        if dst == 8000 and str(src) in baseline:
            old = statistics.median(float(x['elapsed_seconds']) for x in baseline[str(src)]['PHP'])
        new = float(record['elapsed_seconds'])
        results.append({'source_rate':src,'target_rate':dst,'command':command,
                        'baseline_elapsed_median_seconds':old,
                        'elapsed_change_percent':None if old is None else (new / old - 1)*100,
                        'result':record})
        print(f'{src}->{dst}: {new:.3f}s; baseline={old}; delta={results[-1]["elapsed_change_percent"]}', flush=True)
        path = Path(args.output)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({'php':php,'calls':args.calls,'frames_per_call':args.frames,
                                    'results':results}, indent=2) + '\n')

if __name__ == '__main__':
    main()
