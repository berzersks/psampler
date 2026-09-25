#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
    cat <<'EOF'
Uso:
  ./perf_benchmark.sh

Assistente interativo para executar o benchmark de voz com Go ou PHP e gerar
um relatório do perf.
EOF
}

fail() {
    printf 'ERRO: %s\n' "$1" >&2
    exit "${2:-1}"
}

read_answer() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local entered_value

    printf '%s [%s]: ' "$prompt" "$default_value"
    if ! IFS= read -r entered_value; then
        printf '\n' >&2
        fail 'entrada encerrada antes da execução'
    fi

    printf -v "$variable_name" '%s' "${entered_value:-$default_value}"
}

ask_choice() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    shift 3

    local answer
    while true; do
        read_answer answer "$prompt" "$default_value"
        answer=${answer,,}

        local option
        for option in "$@"; do
            if [[ $answer == "$option" ]]; then
                printf -v "$variable_name" '%s' "$answer"
                return
            fi
        done

        printf 'Valor inválido. Opções: %s.\n' "$*" >&2
    done
}

ask_yes_no() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local answer

    while true; do
        read_answer answer "$prompt" "$default_value"
        answer=${answer,,}

        case "$answer" in
            s|sim|y|yes)
                printf -v "$variable_name" '%s' 'yes'
                return
                ;;
            n|nao|não|no)
                printf -v "$variable_name" '%s' 'no'
                return
                ;;
            *)
                printf 'Responda com s ou n.\n' >&2
                ;;
        esac
    done
}

ask_positive_integer() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local answer

    while true; do
        read_answer answer "$prompt" "$default_value"
        if [[ $answer =~ ^[1-9][0-9]*$ ]]; then
            printf -v "$variable_name" '%s' "$answer"
            return
        fi

        printf 'Digite um número inteiro maior que zero.\n' >&2
    done
}

ask_positive_even_integer() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local candidate

    while true; do
        ask_positive_integer candidate "$prompt" "$default_value"
        if ((candidate % 2 == 0)); then
            printf -v "$variable_name" '%s' "$candidate"
            return
        fi

        printf 'Digite um número inteiro par.\n' >&2
    done
}

ask_duration() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local answer

    while true; do
        read_answer answer "$prompt" "$default_value"
        if [[
            $answer =~ ^[0-9]+([.][0-9]+)?$
            && ! $answer =~ ^0+([.]0+)?$
        ]]; then
            printf -v "$variable_name" '%s' "$answer"
            return
        fi

        printf 'Digite uma duração maior que zero, usando ponto para decimais.\n' >&2
    done
}

ask_chunk() {
    local variable_name=$1
    local default_value=$2
    local answer

    while true; do
        read_answer answer 'Tamanho do chunk em bytes ou variable' "$default_value"
        answer=${answer,,}

        if [[ $answer == 'variable' ]]; then
            printf -v "$variable_name" '%s' "$answer"
            return
        fi

        if [[ $answer =~ ^[1-9][0-9]*$ ]] && ((answer % 2 == 0)); then
            printf -v "$variable_name" '%s' "$answer"
            return
        fi

        printf 'Digite variable ou um número inteiro, par e maior que zero.\n' >&2
    done
}

ask_nonempty() {
    local variable_name=$1
    local prompt=$2
    local default_value=$3
    local answer

    while true; do
        read_answer answer "$prompt" "$default_value"
        if [[ -n $answer ]]; then
            printf -v "$variable_name" '%s' "$answer"
            return
        fi

        printf 'O valor não pode ficar vazio.\n' >&2
    done
}

if (($# > 0)); then
    case "$1" in
        --help|-h)
            usage
            exit 0
            ;;
        *)
            usage >&2
            fail 'este script não recebe argumentos; responda às perguntas interativas' 2
            ;;
    esac
fi

command -v perf >/dev/null 2>&1 || fail 'perf não foi encontrado no PATH' 127

language='php'
duration='10'
calls='50'
mode='bytebuffer'
#throughput realtime
runtime_mode='realtime'
frame_bytes='1920'
chunk='1024'
ptime_ms='20'
frequency='999'
call_graph='dwarf'
data_file='perf.data'
flat_file='perf-flat.txt'
use_sudo='no'

perf_event_paranoid=''
if [[ -r /proc/sys/kernel/perf_event_paranoid ]]; then
    IFS= read -r perf_event_paranoid </proc/sys/kernel/perf_event_paranoid
fi

if [[
    $EUID -ne 0
    && $perf_event_paranoid =~ ^-?[0-9]+$
]] && ((perf_event_paranoid >= 3)); then
    use_sudo='yes'
fi

printf 'Benchmark com perf\n\n'
ask_choice language 'Qual deseja executar? (go/php)' "$language" go php
ask_duration duration 'Quantos segundos deseja rodar?' "$duration"
ask_yes_no configure_advanced 'Deseja definir valores avançados? (s/N)' 'n'

if [[ $configure_advanced == 'yes' ]]; then
    printf '\nConfigurações avançadas (Enter mantém o valor exibido)\n'
    ask_positive_integer calls 'Quantidade de chamadas simultâneas' "$calls"
    ask_choice mode 'Implementação do acumulador (string/bytebuffer)' "$mode" \
        string bytebuffer
    ask_choice runtime_mode 'Modo de execução (throughput/realtime)' \
        "$runtime_mode" throughput realtime
    ask_positive_even_integer frame_bytes 'Tamanho do frame PCM16 em bytes' \
        "$frame_bytes"
    ask_chunk chunk "$chunk"
    ask_positive_integer ptime_ms 'Intervalo dos pacotes em milissegundos' \
        "$ptime_ms"
    ask_positive_integer frequency 'Frequência de amostragem do perf em Hz' \
        "$frequency"
    ask_choice call_graph 'Modo de call graph do perf (dwarf/fp/lbr)' \
        "$call_graph" dwarf fp lbr
    ask_nonempty data_file 'Arquivo de dados do perf' "$data_file"
    ask_nonempty flat_file 'Arquivo do relatório textual' "$flat_file"

    sudo_default='n'
    [[ $use_sudo == 'yes' ]] && sudo_default='s'
    ask_yes_no use_sudo 'Executar o perf com sudo? (s/n)' "$sudo_default"
fi

case "$language" in
    go)
        [[ -x ./voice_benchmark_go_debug ]] || \
            fail 'voice_benchmark_go_debug não existe ou não é executável; compile com: go build -o voice_benchmark_go_debug voice_benchmark.go'
        benchmark_command=(./voice_benchmark_go_debug)
        ;;
    php)
        [[ -x ./php ]] || fail 'o binário ./php não existe ou não é executável'
        [[ -f ./voice_benchmark.php ]] || fail 'voice_benchmark.php não foi encontrado'
        benchmark_command=(./php ./voice_benchmark.php)
        ;;
esac

benchmark_command+=(
    "--calls=$calls"
    "--mode=$mode"
    "--runtime=$runtime_mode"
    "--frame=$frame_bytes"
    "--chunk=$chunk"
    "--ptime=$ptime_ms"
    "--duration=$duration"
)

original_uid=$(id -u)
original_gid=$(id -g)
record_command=(perf record)
profiled_command=("${benchmark_command[@]}")

if [[ $use_sudo == 'yes' && $EUID -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || fail 'sudo não foi encontrado no PATH' 127

    sudo -v
    record_command=(sudo -- perf record)
    profiled_command=(
        sudo
        "--user=#${original_uid}"
        "--group=#${original_gid}"
        --
        "${benchmark_command[@]}"
    )
elif [[
    $EUID -ne 0
    && $perf_event_paranoid =~ ^-?[0-9]+$
]] && ((perf_event_paranoid >= 3)); then
    fail "kernel.perf_event_paranoid=$perf_event_paranoid bloqueia a gravação sem sudo"
fi

printf '\nIniciando: '
printf '%q ' "${benchmark_command[@]}"
printf '\nDuração: %s segundo(s)\n\n' "$duration"

"${record_command[@]}" \
    --freq "$frequency" \
    --call-graph "$call_graph" \
    --no-buildid-mmap \
    --output "$data_file" \
    -- \
    "${profiled_command[@]}"

if [[ $use_sudo == 'yes' && $EUID -ne 0 ]]; then
    sudo -- chown "${original_uid}:${original_gid}" "$data_file"
fi

perf report \
    --input "$data_file" \
    --stdio \
    --no-children \
    --percent-limit 0 \
    --sort comm,dso,symbol \
    >"$flat_file"

printf '\nDados do perf: %s\n' "$data_file"
printf 'Relatório: %s\n' "$flat_file"
