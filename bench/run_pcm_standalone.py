#!/usr/bin/env python3
"""Reproducible sequential PCM matrix and hybrid-PMU profiles (Linux)."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import statistics
import subprocess

ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--output',default='bench/results/pcm-standalone-2026-10-02')
p.add_argument('--php',default='./php_pcm_shared')
p.add_argument('--cpu',default='2')
p.add_argument('--repeats',type=int,default=3)
p.add_argument('--skip-perf',action='store_true')
a=p.parse_args()
assert a.repeats>0
os.chdir(ROOT)
out=Path(a.output); out.mkdir(parents=True,exist_ok=True)
env=dict(os.environ,GOMAXPROCS='1',LC_ALL='C')
programs={'PHP':[a.php,'pcm_benchmark.php'],'C':['./pcm_benchmark_c'],'Go':['./pcm_benchmark_go']}
base=['--runtime=throughput','--calls=50','--ptime=20','--source-channels=2','--target-rate=8000','--target-channels=1']
def command(language,rate,frames=10000):
    return ['taskset','-c',a.cpu]+programs[language]+base+[f'--frames={frames}',f'--source-rate={rate}']
def run(cmd,path,extra_env=None):
    with path.open('w') as f:
        subprocess.run(cmd,stdout=f,stderr=subprocess.PIPE,text=True,check=True,env=extra_env or env)
    return dict(l.split(': ',1) for l in path.read_text().splitlines() if ': ' in l)
metadata={'cpu':a.cpu,'GOMAXPROCS':1,'repeats':a.repeats,'commands':{},'binaries':{}}
for language,cmd in programs.items():
    metadata['binaries'][language]=hashlib.sha256(Path(cmd[0]).read_bytes()).hexdigest()
for cmd in [['uname','-a'],['lscpu'],['/usr/local/musl/bin/x86_64-linux-musl-gcc','--version'],['go','version'],['perf','version']]:
    result=subprocess.run(cmd,text=True,capture_output=True)
    metadata[' '.join(cmd)]=result.stdout
(out/'environment.json').write_text(json.dumps(metadata,indent=2)+'\n')
rows={}
for rate in [8000,16000,24000,32000,44100,48000,96000]:
    rows[rate]={language:[] for language in programs}
    order=list(programs)
    for repeat in range(a.repeats):
        for language in order[repeat%3:]+order[:repeat%3]:
            cmd=command(language,rate)
            report=run(cmd,out/f'{rate}-{language.lower()}-{repeat+1}.txt')
            assert report['validation']=='ok'
            rows[rate][language].append(report)
            metadata['commands'][f'{rate}-{language}']=shlex.join(cmd)
        reports=[rows[rate][language][-1] for language in programs]
        for key in ['fixture_sha256','downmix_sha256','output_sha256','output_bytes','input_bytes','frames_processed','source_frame_bytes']:
            assert len({r[key] for r in reports})==1,(rate,key)
    print(rate, {lang:round(statistics.median(float(r['cpu_total_seconds']) for r in rs),6) for lang,rs in rows[rate].items()},flush=True)
    (out/'matrix.json').write_text(json.dumps(rows,indent=2)+'\n')
(out/'environment.json').write_text(json.dumps(metadata,indent=2)+'\n')
if not a.skip_perf:
    for rate in [44100,48000]:
        for language in ['C','PHP']:
            name=f'perf-pcm-{language.lower()}-{rate}'
            cmd=command(language,rate,30000)
            record=['perf','record','--freq','999','--call-graph','dwarf','--no-buildid-mmap','-e','cpu_core/cycles/','-o',str(out/f'{name}.data'),'--']+cmd
            with (out/f'{name}-benchmark.txt').open('w') as f, (out/f'{name}-record.txt').open('w') as err:
                subprocess.run(record,stdout=f,stderr=err,env=env,check=True)
            with (out/f'{name}-flat.txt').open('w') as f:
                subprocess.run(['perf','report','--input',str(out/f'{name}.data'),'--stdio','--no-children','--call-graph','none','--percent-limit','0','--sort','comm,dso,symbol','--show-nr-samples'],stdout=f,env=env,check=True)
            stat=['perf','stat','-r','5','-e','task-clock,cpu_core/cycles/,cpu_core/instructions/,cpu_core/branches/,cpu_core/branch-misses/','-o',str(out/f'{name}-stat.txt'),'--']+command(language,rate)
            with (out/f'{name}-stat-benchmarks.txt').open('w') as f:
                subprocess.run(stat,stdout=f,env=env,check=True)
            print(f'{name}: record/report/stat done',flush=True)
    # Allocator control: optional explanatory measurement, separate from matrix.
    for rate in [44100,48000]:
        for repeat in range(a.repeats):
            run(command('PHP',rate),out/f'{rate}-php-libc-{repeat+1}.txt',dict(env,USE_ZEND_ALLOC='0'))
    print('allocator controls done',flush=True)
