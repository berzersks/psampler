# Baseline PCM standalone — 2026-10-02

O C standalone usa o mesmo DSP do psampler. Nas medianas desta execução, a diferença líquida de CPU PHP/C nos cenários com FIR ficou entre -0,42% e +0,84%. Em 44,1 kHz foi +0,06%; em 48 kHz, +0,48%. Essas diferenças são menores que a dispersão observada e não sustentam uma penalidade relevante do PHP sobre o DSP. Também não demonstram overhead exatamente zero.

## Matriz medida

Todas as linhas: 50 calls independentes × 10.000 frames, throughput, PCM16LE stereo → mono, destino 8000 Hz, ptime 20 ms. Total: 500.000 frames e 10.000 segundos de áudio de entrada por execução. Três repetições por linguagem/taxa, ordem PHP/C/Go alternada; valores abaixo são medianas por métrica. Nenhuma medição simultânea entre linguagens.

Percentuais usam **CPU total**, não wall: `PHP/C = 100*(CPU_PHP/CPU_C-1)`, `C/Go = 100*(CPU_C/CPU_Go-1)` e `PHP/Go = 100*(CPU_PHP/CPU_Go-1)`. Positivo significa mais CPU no numerador. FPS usa wall time.

| Fonte Hz | PHP CPU s | C CPU s | Go CPU s | PHP FPS | C FPS | Go FPS | PHP/C % | C/Go % | PHP/Go % | Output bytes | Hashes PHP=C=Go |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|:---:|
| 8000 | 0.213446 | 0.107472 | 0.123429 | 2,341,519 | 4,652,342 | 4,050,676 | +98.61 | -12.93 | +72.93 | 160000000 | sim |
| 16000 | 4.405133 | 4.404048 | 3.944820 | 113,435 | 113,521 | 126,734 | +0.02 | +11.64 | +11.67 | 144000000 | sim |
| 24000 | 4.728934 | 4.748715 | 4.275223 | 105,658 | 105,282 | 116,880 | -0.42 | +11.08 | +10.61 | 149000000 | sim |
| 32000 | 4.992040 | 4.989844 | 4.637572 | 100,106 | 100,142 | 107,749 | +0.04 | +7.60 | +7.64 | 152000000 | sim |
| 44100 | 5.576013 | 5.572482 | 5.012335 | 89,608 | 89,657 | 99,661 | +0.06 | +11.18 | +11.25 | 154000000 | sim |
| 48000 | 5.393034 | 5.367513 | 5.167034 | 92,700 | 93,140 | 96,715 | +0.48 | +3.88 | +4.37 | 154000000 | sim |
| 96000 | 6.393190 | 6.339956 | 6.254800 | 78,187 | 78,844 | 79,932 | +0.84 | +1.36 | +2.21 | 157000000 | sim |

Em 8→8 kHz não há FIR: PHP consumiu aproximadamente 0,106 s a mais por 500.000 frames (0,212 µs/frame), quase duplicando o custo relativo de um pipeline que sozinho custa apenas 0,107 s. O wrapper é mensurável quando o DSP é pequeno. Não extrapole esse percentual para o resampling.

Intervalos mínimo–máximo das três execuções de CPU (não são intervalos de confiança):

| Fonte Hz | PHP s | C s | Go s |
|---:|---:|---:|---:|
| 8000 | 0.212782–0.213745 | 0.107184–0.108331 | 0.123021–0.134887 |
| 16000 | 4.370329–4.507796 | 4.380912–4.423846 | 3.884724–3.985747 |
| 24000 | 4.728396–4.783458 | 4.726358–4.759942 | 4.230681–4.298887 |
| 32000 | 4.989147–5.027489 | 4.984282–5.036548 | 4.637285–4.890440 |
| 44100 | 5.547134–5.613500 | 5.564521–5.651002 | 4.955549–5.064635 |
| 48000 | 5.380956–5.437389 | 5.350113–5.454305 | 5.078420–5.168599 |
| 96000 | 6.350129–6.401203 | 6.318756–6.352505 | 6.249803–6.282917 |

## Carga, hashes e duração preservados

`fixture_sha256`, `downmix_sha256`, `output_sha256`, input/output bytes e frames coincidem em todas as execuções da matriz. Como a última fixture de 10.000 frames é silêncio, o teste adicional comparou cada um dos oito slots individualmente nas sete taxas, com três calls, contra PHP original, PHP recompilado e Go; fixture/downmix também foram conferidos contra referência Python independente.

| Fonte Hz | Samples/canal de entrada | Bytes stereo/frame | Samples mono de saída/frame |
|---:|---:|---:|---:|
| 8000 | 160 | 640 | 160 |
| 16000 | 320 | 1280 | 144 |
| 24000 | 480 | 1920 | 149 |
| 32000 | 640 | 2560 | 152 |
| 44100 | 882 | 3528 | 154 |
| 48000 | 960 | 3840 | 154 |
| 96000 | 1920 | 7680 | 157 |

Os 144/149/152/154/154/157 samples foram mantidos. Não há correção de estado contínuo, flush ou padding adicional. Os contadores de áudio processado usam a duração da **entrada**, não fingem que a saída tem 20 ms.

## Implementação e build

- `psampler_dsp.inc`: extração mecânica dos tipos, FIR/cache, contexto, bounds, rounding, processamento e adapter completo. Única fonte incluída na extensão e em `pcm_standalone.c`.
- `pcm_core.c`: mesmos helpers PCM16LE e downmix, sem alteração.
- `pcm_storage.inc`: mesmo reserve geométrico (4096 inicial) e mesma substituição/free da saída de `PcmBuffer`. Um storage por call, oito warmups antes da janela; contexto/saída continuam alocados por resampling.
- `psampler_platform.h`: adapta somente serviços de host. PHP conserva Zend allocator/TSRM; C usa libc/pthread, sem headers, runtime ou símbolos PHP/Zend/Swoole.

GCC 13.2.0 `/usr/local/musl/bin/x86_64-linux-musl-gcc`, musl e link estático nos dois executáveis C/PHP. Flags DSP efetivas equivalentes, verificadas no DWARF do objeto PHP:

```text
-g -Os -fno-common -ffp-contract=off -fvisibility=hidden
-fstack-protector-strong -fno-ident -fno-math-errno -fPIC
-DNDEBUG -pthread
```

PHP é ZTS; C foi compilado com `-DPSAMPLER_DSP_THREADS` para manter o mutex. Sem LTO, fast-math ou `-march=native`. `lrint` continua inline pelo `-fno-math-errno`. PHP usa suas definições/includes necessários de extensão; C usa `-DPSAMPLER_STANDALONE`. O adapter de hash usa OpenSSL **fora** da janela.

O PHP compartilhado foi recompilado na árvore local existente com `EXTRA_CFLAGS="-g -Os -fstack-protector-strong -fno-ident"`, conservando o restante do Makefile. Binário de comparação: `php_pcm_shared`; `./php` original foi preservado e usado na paridade. Não foi executado o script destrutivo de reconstrução completa.

Os quatro trechos DSP extraídos são textualmente idênticos ao checkout inicial após substituir somente nomes de serviços/tipos do host: [evidência](../bench/results/pcm-standalone-2026-10-02/source-equivalence.txt). `resample_pcm16_block`, `create_context` e downmix conservaram os tamanhos no PHP original/recompilado (0x538, 0x28e, 0x49). No C os adapters do allocator podem mudar instruções de chamada/inlining; isso não altera o algoritmo.

Comandos completos de compilação: [C](../bench/results/pcm-standalone-2026-10-02/build-c.txt), [PHP](../bench/results/pcm-standalone-2026-10-02/build-php.txt). Instruções de reprodução: [README-pcm-benchmark.md](../README-pcm-benchmark.md#c-standalone-mesmo-dsp-sem-phpzendswoole).

## Ambiente e interpretação

Intel Core i5-13450HX híbrido, Linux 7.0.0-34, CPU lógica 2 fixada por taskset (`cpu_core`, CPUs 0–11); CPUs 12–15 são `cpu_atom`. Go 1.27.1 com `GOMAXPROCS=1`; PHP 8.5.10 ZTS com Swoole. Não foi alterado governor/turbo nem isolado o sistema inteiro; afinidade e alternância reduzem, mas não eliminam variação de frequência/carga externa.

CPU vem de `getrusage(RUSAGE_SELF)`; wall de relógio monotônico. As janelas incluem o trabalho e contadores de cada benchmark e excluem fixture, warmup, hash e validação. O `perf` abrange também inicialização/finalização; seus números são apresentados separadamente.

A subtração PHP−C mede a diferença **líquida** do pipeline completo. C puro necessariamente troca o Zend allocator pela libc. Portanto não identifica, isoladamente, custo bruto de Zend, chamada de método, validação e coroutine; um allocator PHP mais eficiente pode compensar parte desses custos. O controle com `USE_ZEND_ALLOC=0` abaixo ajuda a interpretar essa diferença sem alterar DSP ou o baseline principal.

## Controle separado do allocator

PHP com `USE_ZEND_ALLOC=0`, mesma afinidade/carga, três execuções posteriores à matriz. Não substitui o baseline: conserva emalloc wrappers e restante do runtime, mas desativa o heap gerenciado Zend.

| Fonte Hz | PHP padrão mediana s | C mediana s | PHP com libc mediana s | PHP libc/C % | Faixa PHP libc s |
|---:|---:|---:|---:|---:|---:|
| 44100 | 5.576013 | 5.572482 | 5.687144 | +2.06 | 5.685997–5.762213 |
| 48000 | 5.393034 | 5.367513 | 5.531690 | +3.06 | 5.503206–5.539056 |

Esse controle sugere custo da camada restante na ordem de 2–3% quando ambos usam libc. O Zend allocator no PHP padrão compensa parte desse custo no saldo final. Não é uma decomposição exata por função: o PHP ainda passa pelos adapters de alocação e as execuções não são simultâneas. Logo, “diferença líquida inferior a 1%” não equivale a “Zend/Swoole custam literalmente zero”.

## Perf: hotspots e trabalho absoluto

Gravações separadas de PHP e C, cada uma com **1.500.000 frames** (50 × 30.000), `--freq 999 --call-graph dwarf --no-buildid-mmap -e cpu_core/cycles/`, mesma CPU 2. Self cost, sem children, relatório flat com callgraph oculto. Cada perfil possui mais de 16 mil samples totais, mais de 13 mil em `resample_pcm16_block` e zero lost samples. O evento dominante é `cpu_core/cycles/`; não foi usado o menor evento híbrido como critério.

| Fonte | Programa | Samples totais | Samples FIR | CPU medida s | FIR self % | Downmix self % | create_context self % | benchmark/VM self % |
|---:|:---|---:|---:|---:|---:|---:|---:|---:|
| 44100 | PHP | 16765 | 14095 | 16.749623 | 84.11 | 10.88 | 0.63 | 0.58 (execute_ex) |
| 44100 | C | 16803 | 14263 | 16.806004 | 84.91 | 10.41 | 0.66 | 0.39 (pipeline) |
| 48000 | PHP | 16344 | 13658 | 16.321826 | 83.61 | 11.95 | 0.61 | 0.73 (execute_ex) |
| 48000 | C | 16321 | 13646 | 16.317169 | 83.63 | 11.76 | 0.61 | 0.41 (pipeline) |

`create_context` é self cost: custos chamados de mutex/cache/memória aparecem nos próprios símbolos. O PHP também mostra `zend_hash_find_known_hash` (0,64% em 44,1 kHz; 0,40% em 48 kHz) e `zim_PcmBuffer_append` (0,56%/0,59%). O FIR mais downmix responde por cerca de **95%** do perfil PHP; o restante inclui contexto, memória, VM e outros custos. Não atribua todo esse restante exclusivamente ao interpretador.

Para comparar trabalho absoluto, a tabela abaixo normaliza cada perfil para 500.000 frames. “CPU FIR estimada” = CPU da janela × fração de cycles do símbolo / 3; não é cronômetro por função. “Cycles FIR estimados” = contador aproximado do evento × fração / 3. Ambos são estimativas de amostragem; o perfil inclui setup, e frequência e atribuição de instruções introduzem erro.

| Fonte | PHP CPU FIR estimada s | C CPU FIR estimada s | PHP cycles FIR estimados (bilhões) | C cycles FIR estimados (bilhões) |
|---:|---:|---:|---:|---:|
| 44100 | 4.6960 | 4.7567 | 21.1520 | 21.4305 |
| 48000 | 4.5489 | 4.5487 | 20.4808 | 20.4749 |

Os perfis são compatíveis com o mesmo custo dominante do DSP nas duas entradas. Em 48 kHz o FIR estimado é praticamente idêntico. Em 44,1 kHz a pequena diferença favorece PHP; não há evidência de que a passagem por PHP torne o FIR mais lento. Isso é evidência por amostragem, não prova de igualdade ciclo a ciclo.

O perf informou restrição de símbolos do kernel (`kptr_restrict`). Os símbolos de aplicação/DSP foram resolvidos; samples de kernel sem nome permanecem no denominador e no flat. Não foram alteradas permissões/sysctl.

Perf flat: [C 44100](../bench/results/pcm-standalone-2026-10-02/perf-pcm-c-44100-flat.txt), [PHP 44100](../bench/results/pcm-standalone-2026-10-02/perf-pcm-php-44100-flat.txt), [C 48000](../bench/results/pcm-standalone-2026-10-02/perf-pcm-c-48000-flat.txt), [PHP 48000](../bench/results/pcm-standalone-2026-10-02/perf-pcm-php-48000-flat.txt). Os `.data` estão no mesmo diretório local; `perf-pcm-c.data` e `perf-pcm-c-flat.txt` na raiz apontam para 48000.

## Perf stat: cinco repetições

`task-clock,cpu_core/cycles/,cpu_core/instructions/,cpu_core/branches/,cpu_core/branch-misses/`, 50 × 10.000 frames. Valores abaixo são as **médias do perf stat** do processo completo; não são as medianas da janela interna da matriz. Contadores disponíveis, cobertura 99,99–100% quando relatada.

| Fonte | Programa/lote | task-clock s | Cycles bilhões | Instructions bilhões | Branches bilhões | Branch misses milhões | IPC |
|---:|:---|---:|---:|---:|---:|---:|---:|
| 44100 | PHP inicial | 5.62936 | 25.37296 | 49.15249 | 7.29378 | 18.22932 | 1.937 |
| 44100 | C inicial | 5.59806 | 25.19697 | 48.40348 | 7.07277 | 15.28073 | 1.921 |
| 48000 | PHP inicial | 5.78776 | 26.10646 | 50.31190 | 7.56641 | 21.32209 | 1.927 |
| 48000 | C inicial | 5.40992 | 24.40126 | 49.55569 | 7.34390 | 17.24707 | 2.031 |
| 48000 | PHP repetição | 5.44361 | 24.50042 | 50.30632 | 7.56553 | 21.77727 | 2.053 |
| 48000 | C repetição | 5.39892 | 24.33991 | 49.55541 | 7.34444 | 16.98995 | 2.036 |

O lote PHP 48000 inicial contém uma execução discrepante (CPU interna 7,258513 s; outras quatro: 5,366–5,406 s), elevando a variabilidade do task-clock a ±6,46%. Foi preservado integralmente e repetido **o par C/PHP**, uma vez, em arquivos `*-stat-repeat*`. Na repetição a dispersão caiu a ±0,10% PHP e ±0,12% C: task-clock PHP/C +0,83%, cycles +0,66%, instructions +1,52%. Em 44100 no lote inicial: task-clock +0,56%, cycles +0,70%, instructions +1,55%. Não foi escolhido o melhor resultado nem substituída a matriz pelas repetições de perf.

## Verificação e artefatos

- 13/13 PHPTs, nenhum skip/fail: [log](../bench/results/pcm-standalone-2026-10-02/phpt.txt).
- Integração PHP/Go original, incluindo realtime e parser híbrido: [log](../bench/results/pcm-standalone-2026-10-02/integration.txt).
- Paridade forte dos oito slots/sete taxas e casos mono/stereo, upsample, bloco >8192, saída vazia e CLI inválida: [log](../bench/results/pcm-standalone-2026-10-02/parity.txt).
- Mesma paridade com C compilado separadamente com ASan/UBSan, sem achados: [log](../bench/results/pcm-standalone-2026-10-02/sanitizers.txt). Esse binário de teste não foi usado para performance.
- Testes Go: [log JSON](../bench/results/pcm-standalone-2026-10-02/go-test.txt).
- [Matriz completa JSON](../bench/results/pcm-standalone-2026-10-02/matrix.json), [ambiente, comandos e SHA256 dos executáveis](../bench/results/pcm-standalone-2026-10-02/environment.json), [runner reproduzível](../bench/run_pcm_standalone.py).

Nenhum teste foi enfraquecido. As alterações de fast path e flags que já estavam no checkout foram preservadas; o FIR, rounding, contexto, cache e política de buffers não foram otimizados nesta tarefa.
