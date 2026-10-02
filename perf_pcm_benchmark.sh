#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
usage() {
    cat <<'HELP'
Uso: ./perf_pcm_benchmark.sh --language=php|go [opções]
Defaults: --runtime=throughput --calls=50 --frames=10000 --ptime=20
  --source-rate=44100 --source-channels=2 --target-rate=8000 --target-channels=1
  --frequency=999 --callgraph=dwarf
Opções: --php-bin=php --extension=/path/psampler.so --go-bin=./pcm_benchmark_go
  --data=perf-pcm.data --flat=perf-pcm-flat.txt --dry-run
Compile os programas antes. --frames é por chamada; não há limite artificial.
HELP
}
fail() { printf 'ERRO: %s\n' "$*" >&2; exit 1; }
language=php runtime_mode=throughput calls=50 frames=10000 ptime=20
source_rate=44100 source_channels=2 target_rate=8000 target_channels=1
frequency=999 callgraph=dwarf php_bin=./php extension='' go_bin=./pcm_benchmark_go
data=perf-pcm.data flat=perf-pcm-flat.txt dry_run=false
for arg in "$@"; do
    case "$arg" in
        --help|-h) usage; exit 0;;
        --dry-run) dry_run=true;;
        --language=*) language=${arg#*=};;
        --runtime=*) runtime_mode=${arg#*=};;
        --calls=*) calls=${arg#*=};;
        --frames=*) frames=${arg#*=};;
        --ptime=*) ptime=${arg#*=};;
        --source-rate=*) source_rate=${arg#*=};;
        --source-channels=*) source_channels=${arg#*=};;
        --target-rate=*) target_rate=${arg#*=};;
        --target-channels=*) target_channels=${arg#*=};;
        --frequency=*) frequency=${arg#*=};;
        --callgraph=*) callgraph=${arg#*=};;
        --php-bin=*) php_bin=${arg#*=};;
        --extension=*) extension=${arg#*=};;
        --go-bin=*) go_bin=${arg#*=};;
        --data=*) data=${arg#*=};;
        --flat=*) flat=${arg#*=};;
        *) fail "opção desconhecida: $arg";;
    esac
done
[[ $language == php || $language == go ]] || fail 'language deve ser php ou go'
[[ $runtime_mode == throughput || $runtime_mode == realtime ]] || fail 'runtime inválido'
[[ $callgraph == dwarf || $callgraph == fp || $callgraph == lbr ]] || fail 'callgraph inválido'
for key in calls frames ptime source_rate source_channels target_rate target_channels frequency; do
    [[ ${!key} =~ ^[1-9][0-9]*$ ]] || fail "$key deve ser inteiro positivo sem zeros iniciais"
done
[[ $source_channels == 1 || $source_channels == 2 ]] || fail 'source-channels inválido'
[[ $target_channels == 1 || $target_channels == 2 ]] || fail 'target-channels inválido'
(( target_channels <= source_channels )) || fail 'upmix não suportado'
# Python integer arithmetic avoids overflow in preflight reporting.
command -v python3 >/dev/null || fail 'python3 necessário para preflight e contagem de samples'
python3 - "$calls" "$frames" "$ptime" "$source_rate" "$source_channels" "$target_rate" <<'PY'
import sys
calls,frames,ptime,rate,channels,target=map(int,sys.argv[1:])
if not (rate <= 4294967295 and target <= 4294967295 and ptime <= 2147483647):
    raise SystemExit('ERRO: taxa/ptime fora de alcance')
if rate*ptime%1000 or rate*ptime//1000 == 0:
    raise SystemExit('ERRO: ptime deve conter número inteiro positivo de samples')
if calls*frames*(rate*ptime//1000)*channels*2 > 2**63-1:
    raise SystemExit('ERRO: contagem de bytes causa overflow')
print(f'frames totais: {calls*frames}')
print(f'tempo lógico agregado: {calls*frames*ptime/1000:.3f} segundos de áudio')
print(f'tempo lógico por chamada: {frames*ptime/1000:.3f} segundos')
print(f'bytes por frame de entrada: {rate*ptime//1000*channels*2}')
PY
if [[ $language == php ]]; then
    command=("$php_bin")
    [[ -z $extension ]] || command+=(-d "extension=$extension")
    command+=(./pcm_benchmark.php)
else
    command=("$go_bin")
fi
command+=("--runtime=$runtime_mode" "--calls=$calls" "--frames=$frames" "--ptime=$ptime"
    "--source-rate=$source_rate" "--source-channels=$source_channels" "--target-rate=$target_rate" "--target-channels=$target_channels")
record=(perf record --freq "$frequency" --call-graph "$callgraph" --no-buildid-mmap --output "$data" -- "${command[@]}")
printf 'comando completo: '; printf '%q ' "${record[@]}"; printf '\n'
[[ $dry_run == false ]] || exit 0
command -v perf >/dev/null || fail 'perf não encontrado'
"${record[@]}"
LC_ALL=C perf report --input "$data" --stdio --no-children --percent-limit 0 --sort comm,dso,symbol > "$flat"
python3 - "$flat" <<'PY'
import re,sys
text=open(sys.argv[1]).read()
# perf commonly emits either "Samples: N" or "Samples: NK of event ...".
counts=[]
for value,suffix in re.findall(r'Samples:\s*([\d.,]+)\s*([KMG]?)',text,re.I):
    factor={'':1,'K':1000,'M':1000000,'G':1000000000}[suffix.upper()]
    if suffix:
        count=float(value.replace(',',''))*factor
    else:
        count=int(value.replace(',','').replace('.',''))
    counts.append(round(count))
if counts:
    # Use the least sampled event so a second event does not hide a sparse profile.
    n=min(counts)
    print(f'Samples (menor evento): {n}')
    if n<1000:
        print('WARNING: perfil possui poucas amostras; percentuais de hotspots não são confiáveis.\nAumente --frames.')
    elif n>=5000:
        print('perfil possui amostragem adequada para análise de hotspots')
else:
    print('Não foi possível extrair Samples; confira manualmente o relatório.')
PY
printf 'Dados do perf: %s\nRelatório: %s\n' "$data" "$flat"
