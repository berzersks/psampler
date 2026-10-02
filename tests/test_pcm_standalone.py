#!/usr/bin/env python3
"""All fixture slots/rates versus PHP/Go plus Python fixture/downmix oracle."""
import argparse
import os
import shlex
import subprocess
from test_pcm_benchmark import run, oracle, ROOT

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='./php_pcm_shared')
parser.add_argument('--original-php', default='./php')
parser.add_argument('--c', default='./pcm_benchmark_c')
parser.add_argument('--go', default='./pcm_benchmark_go')
c = parser.parse_args()
os.environ['GOMAXPROCS'] = '1'
commands = [shlex.split(c.php)+['pcm_benchmark.php'], shlex.split(c.original_php)+['pcm_benchmark.php'], shlex.split(c.c), shlex.split(c.go)]
for rate in [8000,16000,24000,32000,44100,48000,96000]:
    for frames in range(1,9):
        args=['--calls=3',f'--frames={frames}',f'--source-rate={rate}','--verify']
        reports=[run(cmd,args) for cmd in commands]
        for key in ['fixture_sha256','downmix_sha256','source_frame_bytes','input_bytes','frames_processed']:
            assert len({r[key] for r in reports})==1,(rate,frames,key,[r[key] for r in reports])
        for group in [reports[:2], reports[2:]]:
            for key in ['output_sha256','output_bytes']:
                assert len({r[key] for r in group})==1,(rate,frames,key,[r[key] for r in group])
        if rate == 8000:
            for key in ['output_sha256','output_bytes']:
                assert len({r[key] for r in reports})==1,(rate,frames,key,[r[key] for r in reports])
        fixture,mono=oracle(rate,2)
        assert reports[0]['fixture_sha256']==fixture and reports[0]['downmix_sha256']==mono
        assert all(r['validation']=='ok' for r in reports)
    print(f'{rate}: all 8 output slots, stateful PHP and legacy C/Go, Python fixture/downmix OK', flush=True)
for rate,channels,target,tc,ptime in [(8000,1,8000,1,20),(44100,1,8000,1,20),(48000,2,8000,2,20),(8000,1,48000,1,20),(48000,2,8000,1,200),(1000,2,8000,1,1)]:
    args=['--calls=3','--frames=9',f'--source-rate={rate}',f'--source-channels={channels}',f'--target-rate={target}',f'--target-channels={tc}',f'--ptime={ptime}','--verify']
    reports=[run(cmd,args) for cmd in commands]
    for key in ['fixture_sha256','downmix_sha256']:
        assert len({r[key] for r in reports})==1,(args,key)
    for group in [reports[:2], reports[2:]]:
        for key in ['output_sha256','output_bytes']:
            assert len({r[key] for r in group})==1,(args,key)
    if rate == target:
        for key in ['output_sha256','output_bytes']:
            assert len({r[key] for r in reports})==1,(args,key)
for bad in ['--calls=0','--frames=-1','--frames=18446744073709551616','--runtime=realtime','--source-rate=4294967296','--source-channels=3','--ptime=1','--unknown=1','--calls=9223372036854775807','--duration=nan']:
    p=subprocess.run(shlex.split(c.c)+[bad],cwd=ROOT,capture_output=True)
    assert p.returncode != 0,bad
print('mono/stereo, upsampling, >8192 samples, empty output, CLI errors OK')
