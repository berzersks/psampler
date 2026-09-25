# PCMAnalyzer 0.6.0: ring e análise acústica em um scan

Medição de 25 de setembro de 2026. Escopo: PCM16 mono, 8 kHz, até 15 s. Nenhum
fluxo da SipSwoole foi alterado ou testado. Os números e as referências
determinísticas estão em [`bench/results/2026-09-25`](../bench/results/2026-09-25/).

## 1. Algoritmo de ring anterior

A referência sem modificações é `analyzeRingPcm()` de
`/home/lotus/projetos/libspech/plugins/Utils/libspech/functionsTrunkController.php`
(SHA-256 do arquivo: `b3c2d4b4d545c16af371f9cb96c1abe4f3eea1728ddd836893417377776c2fe4`).
Seu resultado foi salvo antes da otimização em
[`ring-reference.json`](../bench/results/2026-09-25/ring-reference.json).

Para cada janela completa de 500 ms, o PHP copia um `substr`, faz `unpack('v*')`
em 4000 números, converte os números para floats e calcula RMS bruto em uma
passagem. Depois percorre o array 24 vezes, uma por Goertzel com Hann:
14 candidatos de 395 a 460 Hz em passos de 5 Hz e dez frequências de fundo
(250, 300, 350, 500, 600, 700, 850, 1000, 1200, 1500 Hz). Calcula o
melhor nível, a mediana do fundo e a proeminência. Há ainda um `cos` da janela
por amostra e por frequência dentro do laço PHP. Uma janela é `ring` se o
melhor nível é pelo menos −48 dBFS e a proeminência é pelo menos 10 dB;
caso contrário é `silence` se o RMS bruto não supera −50 dBFS, ou `other`.

Depois do processamento PCM, percorre os estados para formar pulsos. Como
o limite de gap interno é 300 ms e o grid é de 500 ms, somente janelas `ring`
consecutivas formam um mesmo pulso. Descarta pulsos com menos de duas janelas,
isto é, menos de 1000 ms. Um DP quadrático sobre os pulsos escolhe a maior
cadeia cujos inícios têm período de 5000 ± 600 ms. Outro percurso procura o
primeiro trecho `other` de pelo menos 300 ms fora de 400 ms das bordas da
cadeia; no grid de 500 ms uma única janela já satisfaz a duração mínima.
A confiança é `0,70 × cycleScore + 0,30 × cleanScore`, arredondada a quatro
casas quando há pulso. `cycleScore` é 0,45 para um pulso sem cadência,
ou `min(1, 0,70 + 0,15 × (matched−2))` com cadência. `cleanScore` é 1 sem
perturbação e 0 com perturbação. Os motivos textuais e a semântica dos campos
foram mantidos.

O trabalho dominante antigo era 24 passagens por 4000 amostras a cada 500 ms,
com chamadas PHP a `cos`, multiplicações e acesso a arrays. O scan acústico
do PCMAnalyzer acrescentava outra leitura completa de PCM e sondas espectrais
selecionadas. O ring antigo fazia uma conversão `unpack` e outra cópia para
array float **por janela**, além de uma cópia `substr`; em 15 s são 30 de cada.

## 2. Recursos compartilháveis

| Feature/cálculo | Ring anterior | PCMAnalyzer anterior | Compartilhamento atual |
| --- | --- | --- | --- |
| Decode PCM16 | `unpack` por 500 ms | `sample_at` por quadro de 20 ms | Uma decodificação no scan principal; sondas acústicas selecionadas ainda releem bytes |
| Quadrados/RMS | RMS bruto de 500 ms | RMS sem DC de 20 ms | Mesmo sample decodificado; acumuladores e normalizações de janelas distintas |
| DC | Ignorado no estado de ring | Média removida do RMS de 20 ms | Soma também acumulada em 500 ms para RMS AC, pureza e silêncio |
| 425 Hz | Hann de 500 ms, entre 14 candidatos | Retangular de 20 ms, entre 8 bins | Amostra compartilhada; potência de 425 **não** reutilizável numericamente |
| Atividade | `ring/silence/other`, −48/−50 dBFS | `active`, −42 dBFS sem DC | Mesmo scan, decisões independentes |
| Tempo | 500 ms | 20 ms | 25 quadros acústicos alimentam uma janela de ring |
| Goertzel | 24 bins em toda janela de ring | 8 bins em quadros selecionados de cada região | Coeficientes pré-calculados; janelas e recorrências separadas |
| Crossings/diferença | Não usados | Todos os quadros de 20 ms | Permanecem no scan compartilhado |
| Segmentação | Pulsos de ring no grid de 500 ms | Regiões acústicas no grid de 20 ms | Pulsos atualizados durante o scan; regiões acústicas preservadas |
| Entropia/variabilidade | Não usadas | Quadros selecionados | Trabalho acústico preservado, sem espectro em todo quadro |

Não reutilizar a **potência** Goertzel de 425 Hz foi deliberado: Hann/500 ms
e retangular/20 ms produzem níveis diferentes. A meta de não decodificar
novamente cada amostra para ring foi alcançada. Ainda existe segunda leitura
de algumas amostras para as features espectrais acústicas antigas; removê-la
seria outra otimização com risco de alterar os resultados existentes.

## 3. Algoritmo e arquitetura novos

```text
PCM16 -> scan de 20 ms -> RMS/DC/crossings/diferença -> estado acústico
                    \-> 24 acumuladores Hann/500 ms -> estado ring/silence/other
                                                     -> pulso corrente
                                                     -> DP de cadência (até 30 janelas)
                                                     -> perturbação (até 30 janelas)
       -> sondas espectrais acústicas selecionadas -> resultado único
```

O C pré-calcula os 24 coeficientes Goertzel e os 4000 pesos Hann por objeto.
Cada amostra é decodificada uma vez no scan principal e alimenta os
acumuladores baratos de 20 ms e os acumuladores de ring. Em silêncio digital
no início da janela, todos os estados Goertzel são zero; a atualização dos
24 estados é omitida até o primeiro sample não zero. A identidade é exata.
O fim de cada janela classifica o estado e abre ou prolonga o pulso corrente.
O DP posterior é pequeno e necessário para preservar a escolha da maior
cadeia; a perturbação é calculada após essa escolha porque depende das bordas
protegidas. Não há segunda passagem PCM de ring na build padrão.

A referência marcou voz, música tonal e ruído como ring em vários fixtures.
A nova classificação exige adicionalmente `ring_level_dbfs − ac_rms_dbfs ≥
1,5 dB`. O RMS AC de 500 ms remove DC; ele também determina silêncio.
São as únicas mudanças intencionais da regra de estado. Os níveis Goertzel,
RMS bruto, frequências, proeminência, formação de pulsos, DP, proteção de
bordas, confiança e cálculo temporal continuam correspondentes à referência
para uma mesma sequência de estados.

A API permanece `$analyzer = new PCMAnalyzer(8000, 20); $result =
$analyzer->analyze($pcm);`. O campo novo `ring` inclui duração analisada,
pulsos e matched pulses, períodos, estados por janela, confiança, motivo,
cadência e perturbação. Cada janela também expõe RMS bruto, RMS AC, pureza,
frequência, nível e proeminência. `ring_from_start_to_end` conserva a semântica
antiga de “há pulso e não há perturbação”; não significa tom contínuo em toda
a gravação. Não foram adicionados conceitos de chamada ou política.

## 4. Equivalência e casos de ring

O gerador [`generate-ring-fixtures.py`](../bench/generate-ring-fixtures.py)
produz 56 entradas determinísticas: silêncio, tom 425 contínuo, 1/2/3 pulsos,
cadência perfeita, jitter, períodos fora da tolerância, pulsos e gaps curtos e
longos, começo/fim deslocados, ring com voz/ruído/música/outro tom, perturbação
curta/longa, ring parcial/interrompido, fontes sem ring, frequências 395/450/460,
amplitude, DC, clipping e ruído. Os seis cenários básicos foram gerados em
1, 3, 5, 10 e 15 s. O verificador independente
[`ring-ground-truth.php`](../bench/ring-ground-truth.php) passou **56/56**:
pulsos, tamanho da cadeia, presença de cadência e início da perturbação.

Na comparação contra o PHP imutável, **34/56 fixtures ficaram iguais nos
campos da referência**. Outros **22** tiveram **180 estados de janela alterados** pelas duas
regras declaradas; nenhuma diferença inesperada. Todas as métricas antigas de
janela que não definem o estado (RMS bruto, frequência, nível e proeminência)
ficaram exatamente iguais após arredondamento de duas casas: maior diferença
numérica **0**. O arquivo
[`ring-comparison.json`](../bench/results/2026-09-25/ring-comparison.json)
lista os 22 casos com pulsos, matched pulses, perturbação e confiança antes e
depois. [`ring-native.json`](../bench/results/2026-09-25/ring-native.json)
guarda o resultado nativo completo de cada fixture, inclusive inícios/fins,
durações e períodos para auditoria lado a lado. Exemplos importantes:

| Fixture | Pulsos antes → depois | Disturbance antes → depois | Confiança antes → depois |
| --- | ---: | ---: | ---: |
| voz isolada (5 s) | 1 → 0 | nenhum → 0 ms | 0,615 → 0 |
| música isolada (5 s) | 1 → 0 | nenhum → 0 ms | 0,615 → 0 |
| ruído isolado (5 s) | 1 → 0 | 2000 → 0 ms | 0,315 → 0 |
| ring + voz | 2 → 1 | nenhum → 2000 ms | 0,615 → 0,315 |
| ring + música | 2 → 1 | nenhum → 2000 ms | 0,615 → 0,315 |
| ring + ruído | 1 → 1 | 2500 → 2000 ms | 0,315 → 0,315 |
| ring com DC | 1 → 1 | 1500 ms → nenhum | 0,315 → 0,615 |
| 2 pulsos corretos | 2 → 2 | nenhum → nenhum | 0,79 → 0,79 |
| 3 pulsos corretos | 3 → 3 | nenhum → nenhum | 0,895 → 0,895 |

Inícios/fins/durações dos pulsos e gaps são iguais no grid de 500 ms quando
o estado não mudou. Nos casos corrigidos, as mudanças decorrem dos estados
identificados acima e estão no relatório por fixture; não foram escondidas
por tolerância temporal. O critério de pureza é heurístico e precisa de corpus
real rotulado antes de ser tratado como limiar universal.

## 5. Equivalência acústica e C/Go

Antes de alterar C, foram registrados SHA-256 do JSON dos 95 fixtures
acústicos existentes. Depois da mudança, removendo apenas a chave nova
`ring`, os **95 hashes continuaram idênticos** no build real. Isso cobre
`signal`, durações de voz/tom/ruído/música, segmentos, RMS, crossings,
diferença e espectro. Diferenças acústicas: **zero**; maior diferença
numérica: **zero**. O snapshot está em
[`acoustic-baseline.sha256`](../bench/results/2026-09-25/acoustic-baseline.sha256).

C e Go executam os mesmos 20/500 ms, bins, pesos Hann, thresholds,
classificações, pulso, DP e perturbação. Os resultados completos de
**151/151 fixtures** tiveram SHA-256 canônico igual, incluindo ring, acoustic
features, segmentos e frames. Números flutuantes foram normalizados a quatro
casas na serialização canônica; os valores de janela expostos foram arredondados
igualmente a duas casas. Diferenças de digest: **zero**. Ver
[`c-go-comparison.json`](../bench/results/2026-09-25/c-go-comparison.json).

## 6. Método de benchmark

CPU: Intel Core i5-13450HX, 10 núcleos físicos/16 lógicos. Single core fixado
ao CPU lógico 2 com `taskset`; Go com `GOMAXPROCS=1`; um processo PHP e um
`PCMAnalyzer`. Concorrência em CPUs 2/4/6/8, de núcleos físicos distintos.
Cada duração usa os mesmos seis inputs determinísticos, 25 warmups e pelo
menos 1000 jobs por implementação (na concorrência, 1000 **por worker**).
Os inputs e construtores ficam fora da janela medida. Foram medidos p50,
p95, p99, máximo, CPU/job, jobs/s, RSS/peak RSS; Go também B/op e allocs/op.
O checksum usa evidências acústicas e ring; não há JSON, `fmt`, reflexão ou
`interface{}` no hot loop Go.

O build real é `buildspc.sh`, que usa `-Os` e PHP estático ZTS para validar
compatibilidade. As medições de linguagem usam extensão dinâmica GCC
`-O3 -fno-fast-math -mtune=generic` em PHP 8.4 e Go 1.27.1 otimizado.
`-march=native` e intrinsics não foram usados. A comparação de arquitetura
antiga/separada/unificada usa GCC `-O2` igualmente para as três extensões.
`unified-core.c` mede C puro sem Zend; `unified-benchmark.php` mede a chamada
real `$analyzer->analyze($pcm)` incluindo criação do array PHP. Um único
runner reproduz as builds e medições:
[`run-unified-bench.sh`](../bench/run-unified-bench.sh).

## 7. Custo adicional e segunda passagem (Zend, `-O2`)

| Duração | Acústico antigo p50 | Unificado p50 | Unificado CPU/job | Separado p50 | Separado CPU/job | Economia CPU do unificado |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 s | 0,0433 ms | 0,0900 ms | 0,0843 ms | 0,0984 ms | 0,0948 ms | 11,1% |
| 3 s | 0,0896 ms | 0,2391 ms | 0,2101 ms | 0,2634 ms | 0,2366 ms | 11,2% |
| 5 s | 0,1206 ms | 0,3704 ms | 0,3116 ms | 0,4126 ms | 0,3694 ms | 15,6% |
| 10 s | 0,1928 ms | 0,7097 ms | 0,6258 ms | 0,7777 ms | 0,7120 ms | 12,1% |
| 15 s | 0,2961 ms | 1,0347 ms | 0,8862 ms | 1,1476 ms | 1,0779 ms | 17,8% |

O ring adiciona trabalho real: 24 recorrências por amostra de 500 ms. O
unificado elimina a segunda decodificação completa e economiza de 11% a
18% de CPU/job frente à variante separada **com a mesma saída**. A variante
separada existe só sob `PCM_RING_SEPARATE`, para este A/B. O build `-O3`
reduziu o CPU/job do unificado de 0,8862 para 0,7138 ms em 15 s (19,5%)
sem quebrar os hashes; a build real continua `-Os`.

## 8. C puro, Zend e Go (`-O3` C; Go padrão)

Valores por duração. RSS é pico em KiB do processo; o RSS C puro não inclui
PHP, e o RSS Zend inclui o interpretador. Todas as linhas têm 1000 jobs.

| Duração | Impl. | p50 | p95 | p99 | max | CPU/job | jobs/s | peak RSS |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 s | C core | 0,0720 | 0,0927 | 0,1238 | 0,1450 | 0,0673 | 14857 | 2480 |
| 1 s | C Zend | 0,0734 | 0,0956 | 0,1283 | 0,1933 | 0,0691 | 14460 | 17160 |
| 1 s | Go | 0,1992 | 0,2115 | 0,2260 | 0,3394 | 0,1732 | 5774 | 3732 |
| 3 s | C core | 0,1888 | 0,2725 | 0,2834 | 0,4094 | 0,1648 | 6069 | 2608 |
| 3 s | C Zend | 0,1890 | 0,2737 | 0,3455 | 0,4312 | 0,1706 | 5863 | 17416 |
| 3 s | Go | 0,5636 | 0,8785 | 0,9871 | 1,0935 | 0,4801 | 2082 | 4368 |
| 5 s | C core | 0,2867 | 0,4279 | 0,4452 | 0,5768 | 0,2504 | 3992 | 2864 |
| 5 s | C Zend | 0,2869 | 0,4417 | 0,5640 | 0,6639 | 0,2606 | 3838 | 17816 |
| 5 s | Go | 0,9036 | 0,9807 | 1,0955 | 1,3331 | 0,7049 | 1418 | 4912 |
| 10 s | C core | 0,5282 | 0,8229 | 0,8573 | 1,1920 | 0,4760 | 2101 | 3468 |
| 10 s | C Zend | 0,5280 | 0,8245 | 1,0445 | 1,3506 | 0,4843 | 2064 | 18680 |
| 10 s | Go | 1,7542 | 1,9256 | 2,3290 | 2,7356 | 1,3814 | 724 | 5828 |
| 15 s | C core | 0,7701 | 1,2202 | 1,3066 | 1,6605 | 0,7038 | 1421 | 3988 |
| 15 s | C Zend | 0,7724 | 1,2278 | 1,5026 | 2,0125 | 0,7138 | 1401 | 19624 |
| 15 s | Go | 2,6322 | 2,9635 | 3,5702 | 4,1028 | 2,0905 | 478 | 6752 |

Unidades de latência e CPU/job: ms. Em Go, B/op foi 13,472 nos cinco
tamanhos; allocs/op foi aproximadamente 0,511 (ponteiro opcional de
perturbação nos inputs com áudio), medidos por `runtime.MemStats` fora do
hot loop. Alocações Zend/job não foram isoladas com precisão. C core e Zend
ficaram próximos: a diferença observada de CPU/job foi 0,0018 ms em 1 s e
0,0100 ms em 15 s. Ela inclui custo do binding, criação de arrays e diferenças
entre runners; não é uma medida isolada e causal do custo de conversão Zend.

Nesta máquina e nestes inputs, C Zend `-O3` usou 2,51 a 2,93 vezes menos
CPU/job que Go, dependendo da duração. Go usou menos RSS: em 15 s,
6,8 MiB contra 19,2 MiB do processo PHP. Nenhum resultado foi ajustado para
dar vitória a uma linguagem.

## 9. Alternativas internas e perfil

Leitura direta PCM versus pré-decode para um array `int16_t[120000]` foi
medida em A/B/B/A no core `-O3`, 1000 jobs por duração. A alternativa scratch
produziu os mesmos 151 digests, mas usou mais CPU no agregado: em 15 s,
direto 0,6949/0,6901 ms versus scratch 0,7610/0,7196 ms nas duas passagens.
Em 5 s houve empate próximo do ruído. O scratch de 240 KiB não foi adotado;
o experimento permanece reproduzível com `PCM_PREDECODE_SCRATCH`.

O perfil `gprof` do core escalar `-O2 -pg -fno-inline`, antes/depois de omitir
as atualizações em silêncio digital, mostrou tempo amostrado total de
2,11 s → 1,62 s. Antes: `ring_sample` 72,5%, `scan_frame` 15,2%,
`sample_at` 9,5%, `spectral` 1,9%. Depois: `ring_sample` 61,1%,
`scan_frame` 22,2%, `sample_at` 12,4%, `spectral` 3,1%. A fração de outras
rotinas aumenta quando ring fica mais barato; a rotina de estado/cadência
não atingiu 1% das amostras. Em Go, `pprof` atribuiu 68,2% a `ringSample`,
18,2% a `scan`, cerca de 6,5% a decodificação e 1,8% a `spectral`;
`math.archLog` ficou em 0,6%. `sqrt` e `qsort` não tiveram custo individual
confiável na amostragem. Um A/B/B/A com insertion sort para as medianas
não mostrou ganho consistente (em 15 s, `qsort` 0,6835/0,7164 ms e insertion
0,7081/0,7131 ms CPU/job), então `qsort` foi mantido.

`perf` não estava acessível (`perf_event_paranoid=4`); os percentuais C são
do perfil instrumentado, não de PMU. `-O3` autovetorizou blocos com vetores
de 128 bits no baseline genérico x86-64. Não há intrinsics nem requisito
AVX2; a build permanece portátil para CPUs x86-64 comuns compatíveis com PHP.

## 10. Concorrência e memória

Medidas de 15 s, 1000 jobs por worker, C Zend `-O3` versus Go. Cada worker
C é um processo PHP; Go usa um processo com o mesmo número de analyzers.

| Núcleos | C jobs/s | Go jobs/s | C CPU/job | Go CPU/job | C RSS pico agregado | Go RSS pico |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | 1401 | 478 | 0,7138 ms | 2,0905 ms | 19624 KiB | 6752 KiB |
| 2 | 2712 | 928 | 0,710 ms | 2,057 ms | 39128 KiB | 7108 KiB |
| 4 | 4909 | 1718 | 0,795 ms | 2,235 ms | 78580 KiB | 7704 KiB |

O ganho de throughput C de 1→4 núcleos foi 3,50×; Go, 3,59×. RSS agregado
dos processos PHP cresce quase linearmente com workers; o processo Go
compartilha runtime e código. Os arquivos JSONL guardam p50/p95/p99/max,
CPU/job, jobs/s e RSS de todas as durações e quantidades de workers.

## 11. Segurança, regressão e build real

ASan+UBSan com LeakSanitizer: **100.000 chamadas passaram** em core C, com
entradas variáveis, múltiplos analyzers e create/destroy. O stress pelo PHP
estático: **100.000 chamadas e 1024 casos aleatórios**, RSS inicial
19068 KiB, pico/final 20948 KiB; o aumento de 1880 KiB inclui buffers e
retenção do alocador, sem erro de sanitizer. Foram exercitados 0/2/320 bytes,
quadro parcial, 240000/240002 bytes, comprimento ímpar, zero, 0x7fff,
0x8000, 0xffff, 64 e mais de 64 segmentos, múltiplos objetos e alternância
entre inputs com/sem ring. Estado de pulso e perturbação não vazou entre
chamadas. Cinco PHPTs passaram: PCMAnalyzer, ring, Resampler/LPCM e funções
estéreo antigas, ByteBuffer e seu stress.

`buildspc.sh` real passou com PHP estático ZTS; `./php --ri psampler` mostra
versão **0.6.0** e `./php` retornou ring em teste funcional curto. Esse build
prova compatibilidade, não serviu à comparação de velocidade contra Go.

## 12. Limites e próximos experimentos de DSP

- Grid de 500 ms limita as bordas temporais de ring; pulsos com menos de 1 s
  não entram em `pulses`, embora o estado de um quadro possa mostrar ring.
- O limiar de pureza de 1,5 dB foi testado em fixtures sintéticos. Um corpus
  real com voz, música, ruído, gateways deslocados e sinais misturados é
  necessário para estimar falsos positivos e falsos negativos.
- A análise acústica espectral ainda relê amostras selecionadas. O pré-decode
  integral perdeu neste hardware; uma integração mais profunda exigiria
  preservar os 95 hashes acústicos.
- A maior parte do CPU está nas 24 recorrências Goertzel. Uma alternativa
  escalar ou autovetorizável para avaliar a banda pode ser investigada, desde
  que os resultados C/Go e o corpus rotulado continuem equivalentes.
- `perf` e uma medição isolada de allocations Zend não estavam disponíveis.
  Os p95/p99/max dependem da mistura de inputs e do escalonamento do host;
  novas máquinas devem executar o script reprodutível.
- A versão Go reutiliza arrays no analyzer; resultados que contenham slices
  devem ser consumidos ou copiados antes de chamar `Analyze` novamente no
  mesmo objeto. Isso não afeta os resultados independentes retornados por
  cada job do benchmark.
