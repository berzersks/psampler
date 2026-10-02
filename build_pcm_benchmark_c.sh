#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
# Override all flags explicitly to match the PHP build being compared.
# Defaults mirror the local psampler object's DW_AT_producer, not -O3/native.
cc=${CC:-cc}
read -r -a cflags <<< "${CFLAGS:--g -Os -fno-common -ffp-contract=off -fvisibility=hidden -fstack-protector-strong -fno-ident -fno-math-errno -fPIC -DNDEBUG}"
read -r -a cppflags <<< "${CPPFLAGS:-}"
read -r -a ldflags <<< "${LDFLAGS:-}"
read -r -a libs <<< "${LDLIBS:--lcrypto -lm -pthread}"
threads=()
if [[ ${PCM_DSP_THREADS:-1} == 1 ]]; then threads=(-DPSAMPLER_DSP_THREADS); fi
command=("$cc" "${cppflags[@]}" "${cflags[@]}" -Wall -Wextra -DPSAMPLER_STANDALONE "${threads[@]}" -pthread
    pcm_benchmark.c pcm_standalone.c pcm_core.c "${ldflags[@]}" "${libs[@]}" -o pcm_benchmark_c)
printf '%q ' "${command[@]}"; printf '\n'
"${command[@]}"
