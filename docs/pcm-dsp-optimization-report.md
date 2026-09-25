# psampler 0.6.0 — eficiência do núcleo PCM + ring

Rodada de 25 de setembro de 2026, partindo de `0451f551d2fb914f38870ca6729ee09704f75f36`.
Todos os tempos abaixo são para PCM16 mono a 8 kHz. A semântica de pureza
`ring_level − AC_RMS >= 1,5 dB`, os diagnósticos de janela e a API PHP foram
mantidos. Os números brutos e a [síntese por categoria](../bench/results/2026-09-25-dsp/summary.json)
estão em `bench/results/2026-09-25-dsp/`.

## Baseline 0.6.0

A versão de partida já tinha o scan acústico e ring integrado, fast path de
zero digital e 24 Goertzel `double` por janela de 500 ms (14 candidatos e dez
bins de fundo). A integração economizara 11–18% de CPU/job frente à variante
separada. Os resultados anteriores em 15 s, com core/ extensão dinâmica C
`-O3 -fno-fast-math` e Go padrão, eram:

| Implementação | CPU/job | p50 | Referência |
| --- | ---: | ---: | --- |
| C puro | 0,704 ms | 0,770 ms | [`unified-core-o3-final.jsonl`](../bench/results/2026-09-25/unified-core-o3-final.jsonl) |
| C via Zend dinâmico | 0,714 ms | 0,772 ms | [`unified-zend-o3-final.jsonl`](../bench/results/2026-09-25/unified-zend-o3-final.jsonl) |
| Go | 2,091 ms | 2,632 ms | [`unified-go-algorithm-final.jsonl`](../bench/results/2026-09-25/unified-go-algorithm-final.jsonl) |

O ponto de partida tinha 95/95 snapshots acústicos, 56/56 ground truth de ring,
151/151 digests canônicos C/Go, 5/5 PHPT e 100.000 chamadas em ASan/UBSan e
no stress PHP. Esses resultados não foram regenerados nem reescritos para
aceitar diferenças nesta rodada.

## Ambiente e método

CPU Intel Core i5-13450HX; CPUs lógicos 2, 4, 6 e 8 mapeiam aos núcleos
físicos 1, 2, 3 e 4 do mesmo socket. Single core em CPU 2, Go com
`GOMAXPROCS=1`. GCC 13.3.0; Go 1.27.1; PHP SPC 8.5.10 **ZTS**.
`spechshop/pcg729` usado pelo `buildspc.sh`:
`cc6879958a678e92987e60a7909ff053a26075f1`. SHA do psampler de partida:
`0451f551d2fb914f38870ca6729ee09704f75f36`; os arquivos desta rodada
estão no worktree sobre esse commit.

O SPC passa `EXTRA_CFLAGS='-fstack-protector-strong -Os
-D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64 -g -fno-ident -fPIE -fPIC'`.
O `CFLAGS_CLEAN` gerado inclui `-ffp-contract=off` e `-DZTS`.
O [`config.m4`](../config.m4) agora usa a macro PHP `PHP_ADD_SOURCES` para
acrescentar **`-O3 -fno-fast-math` somente após essas flags na regra de
`pcm_analyzer.c` estático**. `psampler.c`, `byte_buffer.c` e o restante do PHP
continuam com as flags SPC. A extensão dinâmica mantém a regra anterior.
Esta é uma regra gerada pelo build PHP, sem edição manual de Makefile.
O [log de build](../bench/results/2026-09-25-dsp/spc-build-final.log) e a regra
do Makefile gerado confirmam a posição final das flags. Os binários SPC antes
e depois são PHP 8.5.10 ZTS da mesma árvore PCG.
O [registro de proveniência](../bench/results/2026-09-25-dsp/build-provenance.txt)
inclui a regra de compilação e SHA-256 dos fontes alterados, já que o trabalho
está no worktree acima do commit de partida.

Cada workload por categoria contém 15 s. Os oito arquivos determinísticos
separam silêncio, ring limpo, voz, ruído, música e ring simultâneo com cada um
desses três tipos. A [geração](../bench/generate-dsp-experiments.py) e os
runners de [C puro](../bench/category-core.c),
[Zend](../bench/category-zend.php) e [Go](../bench/pcmgo/main.go) usam 25 warmups
e 1000 jobs por categoria. Inputs e analyzers ficam fora da região cronometrada.
Diferenças pequenas foram medidas em A/B/B/A, sempre no mesmo CPU; as colunas
de CPU por categoria abaixo são médias das duas execuções de cada variante.
RSS é pico do **processo**, não memória atribuída apenas ao analyzer. Os JSONL
guardam CPU/job, p50/p95/p99, jobs/s e RSS por categoria.

## SPC real: antes e depois

Comparação agregada de 1000 jobs de 15 s nos seis inputs tradicionais,
no **mesmo PHP ZTS**:

| Build | CPU/job | p50 | p95 | p99 | jobs/s | RSS pico |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| SPC original `-Os` | 1,646 ms | 1,900 | 2,506 | 2,676 | 607 | 21.992 KiB |
| SPC com DSP `-O3` | **0,784 ms** | **0,887** | **1,397** | **1,426** | **1275** | 22.000 KiB |
| SPC `-O3`, último build dos fontes finais | **0,786 ms** | **0,886** | **1,401** | **1,426** | **1272** | 22.000 KiB |

**Decisão: adotado.** CPU/job caiu 52,3% no agregado. A/B/B/A nos oito
workloads deu 1,964/1,937 ms no SPC antigo e 0,916/0,914 ms no otimizado,
sem categoria importante piorar. Dois rebuilds deram 0,909 e 0,905 ms na
média das oito categorias; três medições agregadas otimizadas deram 0,784,
0,806 e 0,786 ms. Os 201
hashes completos de resultado foram idênticos entre os binários. A build
final SPC/ZTS passou. Dados:
[`aggregate-spc-before.jsonl`](../bench/results/2026-09-25-dsp/aggregate-spc-before.jsonl),
[`aggregate-spc-final.jsonl`](../bench/results/2026-09-25-dsp/aggregate-spc-final.jsonl)
e [`aggregate-spc-final-build.jsonl`](../bench/results/2026-09-25-dsp/aggregate-spc-final-build.jsonl)
e [`aggregate-spc-last-build.jsonl`](../bench/results/2026-09-25-dsp/aggregate-spc-last-build.jsonl)
e [`summary.json`](../bench/results/2026-09-25-dsp/summary.json).

Visão final das três camadas no mesmo workload agregado de 15 s (os processos
têm runtimes e RSS diferentes):

| Implementação final | CPU/job | p50 | p95 | p99 | jobs/s | RSS pico |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| C core puro `-O3` | 0,686 ms | 0,757 | 1,199 | 1,245 | 1457 | 3.928 KiB |
| C via SPC/Zend final | 0,786 ms | 0,886 | 1,401 | 1,426 | 1272 | 22.000 KiB |
| Go final | 1,730 ms | 2,117 | 2,507 | 2,564 | 578 | 7.224 KiB |

Na mesma rodada, o C puro `-O3` fez 0,686 ms CPU/job e o SPC/Zend fez
0,784–0,806 ms em 15 s. A diferença observada de 0,098–0,120 ms inclui configurações
distintas do runner e do compilador, além do binding; **não** isola custo de
arrays PHP. Não há evidência para investir em micro-otimizações Zend.

## Estratégias de banco Goertzel

As variantes abaixo foram compiladas em `double`, salvo a linha `float`.
“Estágios” faz o scan barato, usa um limite superior de energia para rejeitar
um tom impossível, calcula 14 candidatos e só calcula dez backgrounds quando
nível e pureza ainda permitem ring. O limite vem de Cauchy-Schwarz:
`amplitude <= (4/4000) × sqrt(Σsample² × ΣHann²)`, com
`ΣHann² = 1499,625`. Foi usado 1499,626 como limite superior. Essa decisão
foi equivalente em 201/201 inputs em C e Go, mas bins omitidos mudam
`prominence` e outros diagnósticos em 85/201 inputs. É um **experimento de evidência de decisão**,
inadequado para a saída pública atual.

| Variante | C puro: CPU/job médio | Go: CPU/job médio | Equivalência / decisão |
| --- | ---: | ---: | --- |
| Banco inline original, 24 bins | **0,760 ms** | 2,377 ms | 201/201 completos; C mantido |
| Energia → 14 candidatos → 10 fundos condicionais | 1,546 ms | 1,827 ms | Evidência 201/201; diagnósticos diferentes; rejeitado |
| Segunda leitura, 14 + 10 sempre | 1,325 ms | 2,149 ms | 201/201 completos; rejeitado |
| Segunda leitura, 24 bins em um loop | 0,954 ms | **1,992 ms** | 201/201 completos; rejeitado no C, **adotado no Go** |

No C, a fusão com o scan e a vetorização do loop de 24 estados continuam mais
baratas que a segunda leitura: em ring limpo, 0,764 ms inline contra
0,954 ms com os 24 bins na segunda leitura e 1,346 ms dividindo 14 + 10;
a versão em estágios chegou a 2,034 ms.
No Go, mover os 24 estados para **uma** leitura contígua ao fechar a janela
reduziu CPU/job de 2,377 para 1,992 ms em A/B/B/A (16,2%), incluindo o
atalho exato para janelas de zero digital. Ring limpo caiu de 2,669 para
2,140 ms; voz, ruído, música e misturas também melhoraram. Silêncio ficou
em 0,510 versus 0,496 ms, diferença pequena, sem regressão relevante.
O Go limpa a referência ao PCM ao sair de `Analyze`, inclusive em erro.

O modo de decisão em estágios no Go alcançou 1,827 ms por job, mais 8,3%
abaixo do Go com diagnósticos completos, mas altera campos de janela. Isso
quantifica o teto possível de um futuro modo separado; **nenhuma API nova**
foi criada nesta rodada. O modo em estágios no C perdeu CPU mesmo sem
entregar os diagnósticos completos.

### CPU/job por áudio (15 s; ms)

| Áudio | SPC `-Os` | SPC DSP `-O3` | C inline | Go inline | Go 24 em segunda leitura |
| --- | ---: | ---: | ---: | ---: | ---: |
| Silêncio | 0,573 | 0,212 | 0,202 | 0,510 | 0,496 |
| Ring limpo | 2,040 | 0,885 | 0,764 | 2,669 | 2,140 |
| Voz | 1,942 | 0,903 | 0,784 | 2,581 | 2,130 |
| Ruído | 2,624 | 1,397 | 1,175 | 2,719 | 2,475 |
| Música | 2,034 | 0,913 | 0,772 | 2,596 | 2,114 |
| Ring + voz | 2,143 | 0,965 | 0,773 | 2,661 | 2,175 |
| Ring + ruído | 2,162 | 1,073 | 0,844 | 2,612 | 2,225 |
| Ring + música | 2,087 | 0,970 | 0,768 | 2,665 | 2,184 |

Para as variantes rejeitadas, CPU/job por categoria (ms). “14 + 10” significa
duas leituras após o scan; “24” significa uma segunda leitura. Todas as
variantes completas abaixo preservam o atalho de zero digital.

| Áudio | C estágios | C 14 + 10 | C 24 | C unroll | C float | C native | Go estágios | Go 14 + 10 | Go float inline |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Silêncio | 0,201 | 0,201 | 0,201 | 0,199 | 0,201 | 0,214 | 0,508 | 0,565 | 0,503 |
| Ring limpo | 2,034 | 1,346 | 0,954 | 0,765 | 0,552 | 0,581 | 2,204 | 2,327 | 2,056 |
| Voz | 1,076 | 1,343 | 0,936 | 0,810 | 0,573 | 0,593 | 1,566 | 2,271 | 1,971 |
| Ruído | 1,612 | 2,252 | 1,607 | 1,179 | 0,961 | 0,984 | 1,949 | 2,741 | 2,301 |
| Música | 1,315 | 1,334 | 0,951 | 0,774 | 0,557 | 0,576 | 1,572 | 2,223 | 2,018 |
| Ring + voz | 2,050 | 1,342 | 0,980 | 0,774 | 0,561 | 0,584 | 2,234 | 2,283 | 1,997 |
| Ring + ruído | 2,078 | 1,442 | 1,043 | 0,853 | 0,643 | 0,661 | 2,379 | 2,488 | 2,030 |
| Ring + música | 2,006 | 1,345 | 0,961 | 0,778 | 0,556 | 0,576 | 2,202 | 2,295 | 2,036 |

### Latência, vazão e RSS por experimento

Para evitar esconder perdas por áudio, esta tabela usa **ring limpo de 15 s**;
as métricas dos outros sete áudios constam do
[`summary.json`](../bench/results/2026-09-25-dsp/summary.json). Percentis são
de cada execução e a tabela mostra a média das duas repetições A/B/B/A,
exceto variantes de uma execução.

| Variante | CPU/job | p50 | p95 | p99 | jobs/s | RSS pico KiB | Decisão |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| SPC `-Os` | 2,040 | 1,993 | 2,298 | 2,910 | 490 | 19.904 | substituído |
| SPC DSP `-O3` | 0,885 | 0,872 | 0,958 | 1,090 | 1130 | 19.916 | adotado |
| C inline | 0,764 | 0,762 | 0,787 | 0,817 | 1308 | 2.514 | mantido |
| C estágios | 2,034 | 2,016 | 2,161 | 2,441 | 492 | 2.528 | rejeitado |
| C 14 + 10, segunda leitura | 1,346 | 1,322 | 1,544 | 1,670 | 743 | 2.492 | rejeitado |
| C 24, segunda leitura | 0,954 | 0,950 | 0,990 | 1,051 | 1048 | 2.516 | rejeitado |
| C unroll | 0,765 | 0,761 | 0,788 | 0,820 | 1307 | 2.540 | rejeitado |
| C float | 0,552 | 0,549 | 0,567 | 0,588 | 1812 | 2.564 | rejeitado |
| C `-march=native` | 0,581 | 0,577 | 0,634 | 0,689 | 1721 | 2.544 | rejeitado como padrão |
| Go inline | 2,669 | 2,671 | 2,785 | 3,093 | 375 | 4.320 | substituído |
| Go estágios | 2,204 | 2,184 | 2,292 | 2,685 | 454 | 4.406 | rejeitado |
| Go 14 + 10, segunda leitura | 2,327 | 2,270 | 2,776 | 3,363 | 431 | 4.396 | rejeitado |
| Go 24, segunda leitura | 2,166 | 2,150 | 2,269 | 2,530 | 462 | 4.342 | adotado |
| Go float inline | 2,056 | 2,009 | 2,302 | 2,474 | 486 | 4.324 | rejeitado |

## Números, vetorização e organização

O relatório GCC [`vectorization-o3.txt`](../bench/results/2026-09-25-dsp/vectorization-o3.txt)
confirma vetores de 128 bits no banco `double` com `-O3` genérico. Os offsets
de coeficientes, `x` e `y` no objeto C são múltiplos de 16; o compilador já
vetoriza sem intrinsics, mudanças de layout ou `restrict`. O loop com
`-funroll-loops` ficou em 0,766 ms CPU/job médio contra 0,760 ms inline;
**rejeitado** por diferença no ruído. `-march=native` alcançou 0,596 ms no
core local, mas é uma otimização de hardware, testada à parte, sem fallback
portátil implementado; **não adotada** na build padrão.
Não foram adicionados intrinsics. O `qsort` e a leitura direta do PCM
continuam como estavam; insertion sort e pré-decode integral já tinham perdido
nas medições anteriores.

Trocar estados e coeficientes do ring para `float` reduziu a média C de
0,760 para 0,575 ms e a Go inline de 2,377 para 1,864 ms. **Rejeitado por
equivalência**: no caso calibrado de pureza 1,5 dB, C e Go `float` trocaram
`ring` por `other`, embora o valor impresso continuasse 1,50 dB. Houve ainda
diferenças de centésimos em outros campos, inclusive próximos às bordas de
frequência: 26/201 resultados completos diferiram em cada linguagem. O teste
ilustra por que a decisão precisa ser feita antes de
arredondar, em `double`.

## Fronteiras e regressão

O [gerador de fronteiras](../bench/generate-dsp-experiments.py) criou 42
fixtures adicionais: nível −48 ±0,2 dBFS; proeminência 9,8/9,9/10,0/10,1/10,2
dB; pureza 1,3/1,4/1,5/1,6/1,7 dB; misturas com ruído; frequências 390,
394, 395, 396, 400,
420, 425, 430, 459, 460, 461 e 465 Hz; e períodos 4000/4500/5000/5500/6000
ms. A grade de 500 ms só permite testar 5000 ±500 ms dentro do limite
de ±600 ms. Os alvos de proeminência e pureza foram calibrados sobre o PCM
quantizado; [`targets.json`](../bench/dsp-boundaries/targets.json) guarda
parâmetros e valores medidos. Os fixtures de proeminência isolam **esse
diagnóstico**; neles a pureza é inferior a 1,5 dB e o estado não depende da
proeminência. O fast path em estágios não foi aceito, portanto esta limitação
não reduz a cobertura do caminho escolhido, que sempre calcula os 24 bins.
Silêncio de baixa amplitude e comfort noise continuam passando pelo banco;
somente o **zero digital exato** usa o atalho preservado.

O [verificador](../bench/dsp-boundary-check.php) passou 42/42 no SPC final;
`float` falhou precisamente no caso de pureza 1,5. Entre os 201 inputs
originais e novos, o SPC antes/depois teve **201/201 hashes completos iguais**.
O Go final também teve **201/201 resultados completos iguais** ao Go inline.
A comparação canônica C/Go final passou **151/151** no corpus original e
**50/50** nos novos casos, todos com digest idêntico. Os 95 snapshots
acústicos foram preservados: os 95 resultados completos do SPC antigo e do
final são iguais após o build. Nenhum golden foi atualizado para mascarar
mudança.

Para reproduzir os inputs, execute os geradores
`bench/generate-fixtures.php`, `bench/generate-ring-fixtures.py` e
`bench/generate-dsp-experiments.py`. O
[`fixture-sha256.txt`](../bench/results/2026-09-25-dsp/fixture-sha256.txt)
fixa os 42 inputs de fronteira e os oito workloads. Os flags C experimentais
são `PCM_RING_STAGED_EXPERIMENT`, `PCM_RING_FULL_TWO_PASS_EXPERIMENT`,
`PCM_RING_ALL_TWO_PASS_EXPERIMENT` e `PCM_RING_FLOAT`; os equivalentes Go são
tags `ringstage`, `ringfull` e
`ringfloat,ringinline`. A versão inline anterior do Go usa `ringinline`;
a versão mantida é o build padrão. Os runners de categoria e
[`summarize-dsp-results.py`](../bench/summarize-dsp-results.py) regeneram as
tabelas. O C `-funroll-loops` e `-march=native` também tiveram 201/201 hashes
completos iguais, mas foram rejeitados pelos motivos de desempenho e
portabilidade já indicados.

Exemplo para repetir as três medições finais por categoria no CPU 2, após
`./buildspc.sh` e a geração dos PCM:

```sh
gcc -O3 -fno-fast-math -mtune=generic bench/category-core.c pcm_analyzer.c -lm -o /tmp/psampler-category-core
GO111MODULE=off go build -o /tmp/psampler-category-go ./bench/pcmgo
taskset -c 2 /tmp/psampler-category-core bench/dsp-workloads 1000
taskset -c 2 ./php bench/category-zend.php bench/dsp-workloads 1000
GOMAXPROCS=1 taskset -c 2 /tmp/psampler-category-go -mode=category -dir=bench/dsp-workloads -runs=1000
```

## HOTSPOT BEFORE / HOTSPOT AFTER

Perfis C comparáveis, do core instrumentado com `-pg -fno-inline`, antes
`-Os` e depois `-O3`. São percentuais de tempo amostrado, não contadores PMU;
`perf_event_paranoid=4` impediu `perf` nesta máquina.

| C | `ring_sample` | `scan_frame` | `sample_at` | `spectral` |
| --- | ---: | ---: | ---: | ---: |
| BEFORE `-Os` | 66,3% | 22,9% | 2,4% | 3,9% |
| AFTER `-O3` | **51,6%** | 28,1% | 14,8% | 3,1% |

O banco continua o maior custo no C, porém caiu de 1,36 para 0,66 s de
tempo próprio nesse perfil. `sample_at` tornou-se mais visível; isso não é
razão para repetir o pré-decode integral, já medido e derrotado na rodada
anterior. Perfis: [`profile-os.txt`](../bench/results/2026-09-25-dsp/profile-os.txt)
e [`profile-o3.txt`](../bench/results/2026-09-25-dsp/profile-o3.txt).

No Go anterior, `ringSample` era ~68,2% do perfil. Depois da segunda leitura
contígua, [`ringPass` ficou em 57,9%, `ringSample` em 9,1% e `scan` em 19,1%](../bench/results/2026-09-25-dsp/profile-go-final-source.txt).
O hotspot mudou de lugar como esperado; não há motivo para continuar tratando
`ringSample` como alvo principal no Go.

## Concorrência e segurança

15 s, 1000 jobs **por worker**, CPUs físicos 2/4/6/8, mesmos binários finais.
O foco decisório permanece CPU/job.

| Cores | Implementação | CPU/job | jobs/s | p50 | RSS pico |
| ---: | --- | ---: | ---: | ---: | ---: |
| 1 | SPC/Zend | 0,779 ms | 1284 | 0,880 ms | 21.900 KiB |
| 1 | Go | 1,710 ms | 585 | 2,105 ms | 7.228 KiB |
| 2 | SPC/Zend | 0,788 ms | 2519 | 0,885 ms | 43.804 KiB agregado |
| 2 | Go | 1,748 ms | 1062 | 2,120 ms | 7.332 KiB |
| 4 | SPC/Zend | 0,886 ms | 4331 | 0,987 ms | 87.604 KiB agregado |
| 4 | Go | 1,938 ms | 1930 | 2,390 ms | 8.056 KiB |

O runner guarda também p95/p99 em
[`concurrent-last-1.jsonl`](../bench/results/2026-09-25-dsp/concurrent-last-1.jsonl),
[`2.jsonl`](../bench/results/2026-09-25-dsp/concurrent-last-2.jsonl) e
[`4.jsonl`](../bench/results/2026-09-25-dsp/concurrent-last-4.jsonl).
Go final alocou ~13,5 B/job e ~0,51 alloc/job no agregado de 15 s; a referência
temporária ao PCM é removida ao concluir cada análise.

ASan+UBSan com LeakSanitizer passou 100.000 chamadas C, entradas variáveis,
analyzers adicionais e create/destroy. O stress PHP no SPC final passou
100.000 chamadas e 1.024 entradas aleatórias, com múltiplos analyzers; RSS
inicial 19.084 KiB e final/pico 21.028 KiB. Go `go test -race` passou com
1.024 entradas aleatórias, dois analyzers reutilizados e create/destroy. Cinco
PHPT passaram. O ground truth de ring permaneceu 56/56. Evidências:
[`asan-ubsan-lsan-final.txt`](../bench/results/2026-09-25-dsp/asan-ubsan-lsan-final.txt),
[`php-stress-final-build.json`](../bench/results/2026-09-25-dsp/php-stress-final-build.json),
[`phpt-final-build.txt`](../bench/results/2026-09-25-dsp/phpt-final-build.txt),
[`ring-ground-truth-final.json`](../bench/results/2026-09-25-dsp/ring-ground-truth-final.json).
As variantes C estruturais de estágios, 14 + 10, 24 em segunda leitura e
`float` também passaram 100.000 chamadas cada com sanitizers antes de serem
descartadas por saída ou desempenho.

## CURRENT BEST C ALGORITHM / CURRENT BEST GO ALGORITHM

**C:** scan acústico compartilhado com 24 recorrências Goertzel `double`
atualizadas inline; Hann pré-calculada; zero digital preservado. No SPC real,
somente `pcm_analyzer.c` compila com `-O3 -fno-fast-math`. Este é o melhor
caminho C portátil e com diagnósticos completos medido nesta rodada.

**Go:** scan acústico e somas de ring na primeira leitura, seguido de uma
segunda leitura contígua da janela que atualiza os mesmos 24 bins `double`
em um loop; zero digital preservado. Os resultados completos são idênticos ao
Go inline e os digests C/Go continuam iguais. É a melhor organização Go
medida nesta rodada, mesmo usando duas leituras.

Ambas as implementações calculam o mesmo banco, nível, frequência,
proeminência, RMS, pureza, estados, pulsos, cadência e perturbação. A escolha
de organização de dados difere por linguagem porque os dados medidos pediram
isso. Não houve reajuste do threshold de pureza nem mudança de API.

**Decisão:** manter o algoritmo C simples e portátil; otimizar seu arquivo DSP
na build SPC; manter no Go a segunda leitura contígua com diagnósticos
completos. A separação futura entre decisão rápida e diagnóstico completo só
mereceria discussão se um consumidor demonstrasse necessidade real e corpus
real rotulado justificasse o contrato novo.
