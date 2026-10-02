#!/usr/bin/env python3
"""Cross-language integration tests; no external PCM fixture or third-party dependency."""
import argparse
import hashlib
import pathlib
import shlex
import struct
import subprocess
import tempfile
import os

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCENARIOS = [(8000, 1), (8000, 2), (44100, 1), (44100, 2)]

def oracle(rate, channels):
    n = rate // 50
    def tone(index, hz):
        phase = index * hz * 4096 // rate % 4096
        return phase - 1024 if phase < 2048 else 3072 - phase
    def trunc(a, b):
        return (abs(a) // b) * (-1 if a < 0 else 1)
    bank, mono = bytearray(), bytearray()
    edges = [-32768,-32768,32767,32767,-32768,32767,-3,0,3,0,-32768,0,32767,0]
    for i in range(0, len(edges), 2):
        mono += struct.pack('<h', trunc(edges[i] + edges[i+1], 2))
    for slot in range(8):
        for j in range(n):
            index = slot*n+j
            gain = 6+(slot%3)*5+(j*3//n)*3
            l = trunc((tone(index,440)*3+tone(index,997))*gain,4)
            r = trunc((tone(index+17,659)*3-tone(index,123))*gain,4)
            if slot == 7 or n//3 <= j < n//2:
                l = r = 0
            bank += struct.pack('<h', l)
            if channels == 2:
                bank += struct.pack('<h', r)
                mono += struct.pack('<h', trunc(l+r,2))
    return hashlib.sha256(bank).hexdigest(), hashlib.sha256(mono).hexdigest()

def run(command, args):
    p = subprocess.run(command+args, cwd=ROOT, text=True, capture_output=True, check=True)
    return dict(line.split(': ', 1) for line in p.stdout.splitlines() if ': ' in line)

def check_report(report, source, channels, frames, mode):
    assert report['validation'] == 'ok' and report['verify'] == 'true'
    total = 3*frames
    assert int(report['calls']) == 3
    assert int(report['frames_per_call']) == frames
    assert int(report['total_frames']) == int(report['frames_processed']) == total
    assert int(report['source_frame_bytes']) == source//50*channels*2
    assert int(report['source_rate']) == source and int(report['source_channels']) == channels
    assert int(report['ptime_ms']) == 20 and report['runtime_mode'] == mode
    assert int(report['input_bytes']) == total*source//50*channels*2
    assert int(report['target_rate']) == 8000 and int(report['target_channels']) == 1
    assert int(report['output_bytes']) == total*(320 if source == 8000 else 308)
    assert float(report['audio_seconds_processed']) == total*.02
    fixture, downmix = oracle(source, channels)
    assert report['fixture_sha256'] == fixture
    assert report['downmix_sha256'] == downmix
    elapsed = float(report['elapsed_seconds'])
    assert elapsed > 0
    cpu = float(report['cpu_total_seconds'])
    assert abs(cpu-float(report['cpu_user_seconds'])-float(report['cpu_system_seconds'])) < .000003
    # Bound the ratio using the six-decimal printed wall/CPU precision.
    # Tiny scenario A runs can be only microseconds long.
    low = max(0, cpu-.0000005)/(elapsed+.0000005)*100
    high = (cpu+.0000005)/max(elapsed-.0000005, .000000001)*100
    assert low-.001 <= float(report['average_cpu_percent']) <= high+.001
    if mode == 'realtime':
        assert elapsed >= (frames-1)*.02-.001
        assert int(report['deadline_misses']) >= 0
    else:
        assert report['deadline_misses'] == 'n/a'


def test_perf_parser():
    go_dry_run = subprocess.run(
        ['bash', str(ROOT/'perf_pcm_benchmark.sh'), '--language=go', '--calls=1',
         '--frames=1', '--dry-run'], text=True, capture_output=True, check=True)
    assert ' -- env GOMAXPROCS=1 ./pcm_benchmark_go ' in go_dry_run.stdout
    php_dry_run = subprocess.run(
        ['bash', str(ROOT/'perf_pcm_benchmark.sh'), '--language=php', '--calls=1',
         '--frames=1', '--dry-run'], text=True, capture_output=True, check=True)
    assert 'GOMAXPROCS' not in php_dry_run.stdout
    # Execute the actual script against a fake perf to exercise report parsing.
    with tempfile.TemporaryDirectory() as d:
        perf = pathlib.Path(d)/'perf'
        perf.write_text('#!/bin/sh\nif [ "$1" = report ]; then printf "# Samples: %s of event cycles\\n" "$PCM_TEST_SAMPLES"; fi\n')
        perf.chmod(0o755)
        for samples, message in [('9', 'WARNING:'), ('999', 'WARNING:'), ('5K', 'amostragem adequada'), ('5,123', 'amostragem adequada')]:
            env = dict(os.environ, PATH=d+os.pathsep+os.environ['PATH'], PCM_TEST_SAMPLES=samples)
            p = subprocess.run(['bash',str(ROOT/'perf_pcm_benchmark.sh'),'--language=go','--calls=3','--frames=17',
                                '--data='+d+'/perf.data','--flat='+d+'/flat.txt'], text=True,capture_output=True,check=True,env=env)
            assert message in p.stdout and 'frames totais: 51' in p.stdout
        perf.write_text('#!/bin/sh\nif [ "$1" = report ]; then printf "# Samples: 6K of event cpu_core/cycles/\\n# Samples: 9 of event cpu_atom/cycles/\\n"; fi\n')
        p = subprocess.run(['bash',str(ROOT/'perf_pcm_benchmark.sh'),'--language=c','--calls=1','--frames=1',
                            '--data='+d+'/perf.data','--flat='+d+'/flat.txt'], text=True,capture_output=True,check=True,env=env)
        assert 'amostragem adequada' in p.stdout and 'WARNING:' not in p.stdout


def test_php_hot_path():
    source = (ROOT/'pcm_benchmark.php').read_text()
    pipeline = source.split('function pcmPipeline(', 1)[1].split('final class PcmCallState', 1)[0]
    measured = source.split('$initial = memory_get_usage();', 1)[1].split('// Everything below', 1)[0]
    worker = source.split('Swoole\\Coroutine::create(', 1)[1].split('$completed->push(', 1)[0]
    for block in [pipeline, measured, worker]:
        assert 'toString(' not in block and 'hash(' not in block and 'pcmValidate(' not in block

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='PHP command, e.g. "php -n -d extension=/path/psampler.so"')
    parser.add_argument('--go', default='./pcm_benchmark_go')
    options = parser.parse_args()
    php = shlex.split(options.php)+['pcm_benchmark.php']
    go = shlex.split(options.go)
    for index,(rate,channels) in enumerate(SCENARIOS):
        for mode,frames in [('throughput',17),('realtime',3)]:
            args = ['--calls=3',f'--frames={frames}',f'--runtime={mode}',f'--source-rate={rate}',
                    f'--source-channels={channels}','--target-rate=8000','--target-channels=1','--verify']
            p, g = run(php,args), run(go,args)
            check_report(p,rate,channels,frames,mode)
            check_report(g,rate,channels,frames,mode)
            # Resampler hash equality is deliberately not required.
            if rate == 8000:
                assert p['output_sha256'] == g['output_sha256']
            print(f'{chr(65+index)} {mode}: fixture/downmix/counters/metadata/duration OK')
    for command in [php,go]:
        for bad in ['--calls=0','--frames=0','--runtime=unknown','--source-channels=3','--ptime=1','--unknown=1']:
            result = subprocess.run(command+[bad], cwd=ROOT, capture_output=True)
            assert result.returncode != 0, bad
        report=run(command,['--calls=1','--source-rate=8000','--source-channels=1','--duration=0.05'])
        assert report['frames_per_call']=='3'
        report=run(command,['--calls=1','--frames=2','--source-rate=8000','--source-channels=1','--duration=0.05'])
        assert report['frames_per_call']=='2'
    test_perf_parser()
    test_php_hot_path()
    print('argument validation, duration precedence, perf sample warnings and PHP hot path: OK')

if __name__ == '__main__':
    main()
