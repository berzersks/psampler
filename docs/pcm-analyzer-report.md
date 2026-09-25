# PCMAnalyzer 0.5.0: integração e medição

Medição em 24/09/2026, Intel Core i5-13450HX, Linux 7.0.0-31-generic.
JSONs originais, comandos e configuração: [`bench/results/2026-09-24`](../bench/results/2026-09-24/README.md).

## A. Arquitetura

Antes: PCM16 → `EarlyGreetingAnalysis` (`unpack`, `substr`, arrays e múltiplos
loops PHP) → features → `MailboxAcousticClassifier` → `MailboxDecisionPolicy`.

Depois: PCM16 → `PCMAnalyzer` C → features → classificador e política PHP.
O pool global, socket Unix, fila, deadline, fail-open, SIP, ACK, CDR e billing
continuam na SipSwoole. `EarlyGreetingAnalysis` permanece para A/B e rollback.
O worker persistente reutiliza o objeto nativo, cujo `analyze()` zera o estado
de cada job. `mailbox_detected` e o limiar operacional de 800 ms não existem
na extensão. A análise de ring e sua janela de perturbação ainda executam DSP
em PHP.

## B. Arquivos

| Projeto | Arquivos | Mudança |
|---|---|---|
| psampler | `pcm_analyzer.c/.h` | DSP limitado, sem Zend ou política; frames e espectro esparso. |
| psampler | `psampler.c`, `config.m4`, `php_psampler.h`, `CMakeLists.txt` | Binding, build, versão 0.5.0. |
| psampler | `README.md`, `tests/pcm_analyzer*`, `tests/legacy_api.phpt` | API, segurança e regressão. |
| psampler | `bench/generate-fixtures.php`, `bench/pcmgo`, `bench/shadow-compare.php`, `bench/dsp-benchmark.php`, `bench/concurrent-benchmark.py`, `bench/results` | Dataset, Go, equivalência e medições. |
| sipswoole | `bin/mailbox-analysis-worker.php`, `src/Rtp/Analysis/EarlyMediaMailboxAnalyzer.php`, `src/Rtp/Analysis/NativeGreetingAnalysis.php` | Reuso, switch e fail-open. |
| sipswoole | `tests/MailboxNative*`, `tests/MailboxConcurrentFinalizationStress.php`, `bench/mailbox-global-stress.sh`, `docs/mailbox-detector-v2.md` | Testes e operação. |

As alterações locais preexistentes em `buildspc.sh`, `perf_benchmark.sh` e
`voice_benchmark.php` foram preservadas. Foi adicionado `set -euo pipefail` ao
`buildspc.sh` porque uma falha do `spc` podia produzir status zero do shell.
O `cp -r` foi trocado por `rsync` com exclusões para `.git`, binários e
fixtures gerados: objetos Git antigos no diretório de downloads tinham
permissões incompatíveis com uma nova cópia completa.

## C. API

```php
$analyzer = new PCMAnalyzer(sampleRate: 8000, frameDurationMs: 20);
$features = $analyzer->analyze($pcm16leMono);
```

PCM signed 16-bit mono little-endian, 8 kHz, até 240.000 bytes/15 s e 64
regiões. Comprimento ímpar, configuração não suportada, entrada maior e
excesso de segmentos geram `ValueError`. Quadro final parcial é ignorado sem
acesso fora da string. Cada objeto guarda coeficientes e buffers próprios,
sem globals mutáveis nem estado entre jobs.

## D. Segurança de memória

| Verificação | Resultado |
|---|---|
| PHP ZTS: 100.000 chamadas, comprimentos alternados, 15 s repetido | PASS; RSS inicial 19.068 KB, pico/final 20.364 KB, delta +1.296 KB com fuzz. |
| Fuzz de entrada | 1.024 strings de 0–240.002 bytes, tamanhos pares/ímpares, amplitudes extremas: PASS. |
| Núcleo C com ASan + UBSan: 100.000 chamadas | PASS; nenhum erro ou leak. |
| Instâncias alternadas e criação/destruição repetida | PASS; sem crescimento linear por job observado. |
| Quatro processos ZTS simultâneos | 100.000 chamadas por processo; os quatro passaram, delta RSS +1.296 KB cada. |
| Go `testing.B`, voz 15 s | 376.145 ns/op, 0 B/op, 0 allocs/op. |

ASan/UBSan cobriram o núcleo puro, não o Zend inteiro. O teste PHP cobriu o
binding em build ZTS.

## E/F. Compatibilidade e build real

`bash buildspc.sh` concluiu; `./php --ri psampler` mostrou 0.5.0,
`PCMAnalyzer::__construct`, `PCMAnalyzer::analyze`, `Resampler`, `LPCM`,
`ByteBuffer` e as duas funções globais. **Resampler PASS, LPCM PASS,
ByteBuffer PASS, monoToStereo PASS, interleavePcmStereo PASS.** Quatro PHPT
passaram. O script presente usa `--no-strip --enable-zts` e flags `-Os -g`,
sem `--debug`; a descrição histórica do pedido difere dessa árvore. O build
otimizado separado usou PHP 8.5.10 NTS com `-O2 -Wall -Wextra -Wpedantic`,
sem `-march=native`. Go: `go build`, GOARCH=amd64, GOAMD64=v1. Os únicos
warnings da compilação rigorosa vieram de macros/headers Zend.

## G/H. Equivalência

95 PCM determinísticos: 19 formas × 1/3/5/10/15 s. PHP/C/Go tiveram **zero
diferenças** em sinal, segmentos, durações, RMS, cruzamentos, dominância,
entropia, variabilidade, classe e decisão. Tolerância permitida para números
arredondados: 0,002; diferença máxima observada: **0**. Nos 30 cenários
existentes de `MailboxPolicyV2Test`, decisão, confiança e classe PHP/nativo
foram idênticas.

| Fixture | Signal PHP/C/Go | Voice ms PHP/C/Go | Longest ms PHP/C/Go | Decisão igual? |
|---|---|---:|---:|---|
| 1 s 425 Hz | narrowband_tone | 0 | 0 | sim, false |
| 3 s white noise | noise | 0 | 0 | sim, false |
| 5 s voz curta | voice_like | 360 | 360 | sim, false |
| 10 s tom + silêncio + voz | voice_like | 8.200 | 8.200 | sim, true |
| 15 s voz sintética | voice_like | 15.000 | 15.000 | sim, true |
| 15 s jingle | other_audio | 0 | 0 | sim, false |

Comparação explícita PHP/C (a decisão é `mailbox_detected` calculada em PHP):

| Fixture | PHP signal | C signal | PHP voice ms | C voice ms | PHP longest | C longest | Decisão igual? |
|---|---|---|---:|---:|---:|---:|---|
| 425 Hz, 1 s | narrowband_tone | narrowband_tone | 0 | 0 | 0 | 0 | sim |
| White noise, 3 s | noise | noise | 0 | 0 | 0 | 0 | sim |
| Voz curta, 5 s | voice_like | voice_like | 360 | 360 | 360 | 360 | sim |
| Tom/silêncio/voz, 10 s | voice_like | voice_like | 8.200 | 8.200 | 8.200 | 8.200 | sim |
| Voz sintética, 15 s | voice_like | voice_like | 15.000 | 15.000 | 15.000 | 15.000 | sim |
| Jingle, 15 s | other_audio | other_audio | 0 | 0 | 0 | 0 | sim |

Comparação explícita C/Go:

| Fixture | C signal | Go signal | C voice ms | Go voice ms | C longest | Go longest | Decisão igual? |
|---|---|---|---:|---:|---:|---:|---|
| 425 Hz, 1 s | narrowband_tone | narrowband_tone | 0 | 0 | 0 | 0 | sim |
| White noise, 3 s | noise | noise | 0 | 0 | 0 | 0 | sim |
| Voz curta, 5 s | voice_like | voice_like | 360 | 360 | 360 | 360 | sim |
| Tom/silêncio/voz, 10 s | voice_like | voice_like | 8.200 | 8.200 | 8.200 | 8.200 | sim |
| Voz sintética, 15 s | voice_like | voice_like | 15.000 | 15.000 | 15.000 | 15.000 | sim |
| Jingle, 15 s | other_audio | other_audio | 0 | 0 | 0 | 0 | sim |

`shadow-report.json` contém linhas de todos os fixtures e `result_digest`
SHA-256 canônico; C/Go deram o mesmo digest em todos.

## I/J. DSP por núcleo

Mesmo PCM e ordem: 25 warm-ups e 1.000 jobs por duração por implementação,
CPU lógico 4 fixado. O tempo C inclui chamada Zend e resultado PHP, mas exclui
JSON, I/O, IPC, logging e spawn. `PHP buildspc`/`C buildspc` compartilham PHP
ZTS `-Os -g`; `PHP otimizado`/`C otimizado` compartilham PHP 8.5 NTS com
extensão `-O2`. Percentis e CPU/job em ms.

| Duração | Implementação | p50 | p95 | p99 | máximo | CPU/job | jobs/s |
|---:|---|---:|---:|---:|---:|---:|---:|
| 1 s | PHP buildspc | 1.311 | 1.534 | 1.559 | 1.998 | 1.146 | 872.6 |
| 1 s | PHP otimizado | 0.764 | 0.907 | 0.923 | 1.649 | 0.676 | 1478.3 |
| 1 s | C buildspc | 0.071 | 0.089 | 0.091 | 0.105 | 0.066 | 15043.8 |
| 1 s | C otimizado | 0.040 | 0.058 | 0.060 | 0.074 | 0.038 | 26241.2 |
| 1 s | Go otimizado | 0.049 | 0.103 | 0.144 | 0.198 | 0.051 | 19629.0 |
| 3 s | PHP buildspc | 3.575 | 4.187 | 4.241 | 4.289 | 3.134 | 319.1 |
| 3 s | PHP otimizado | 2.118 | 2.520 | 2.555 | 4.149 | 1.889 | 529.3 |
| 3 s | C buildspc | 0.194 | 0.242 | 0.253 | 0.350 | 0.180 | 5568.3 |
| 3 s | C otimizado | 0.099 | 0.158 | 0.163 | 0.363 | 0.096 | 10449.6 |
| 3 s | Go otimizado | 0.118 | 0.181 | 0.201 | 0.255 | 0.114 | 8796.3 |
| 5 s | PHP buildspc | 5.605 | 6.615 | 6.681 | 7.675 | 4.997 | 200.1 |
| 5 s | PHP otimizado | 3.327 | 3.989 | 4.041 | 4.213 | 2.998 | 333.5 |
| 5 s | C buildspc | 0.292 | 0.356 | 0.366 | 0.386 | 0.271 | 3685.1 |
| 5 s | C otimizado | 0.140 | 0.239 | 0.244 | 0.259 | 0.135 | 7387.1 |
| 5 s | Go otimizado | 0.166 | 0.274 | 0.287 | 0.417 | 0.166 | 6034.6 |
| 10 s | PHP buildspc | 10.870 | 12.598 | 12.704 | 12.810 | 9.544 | 104.8 |
| 10 s | PHP otimizado | 6.412 | 7.698 | 9.015 | 10.324 | 5.820 | 171.8 |
| 10 s | C buildspc | 0.536 | 0.648 | 0.663 | 0.731 | 0.497 | 2010.7 |
| 10 s | C otimizado | 0.225 | 0.433 | 0.445 | 0.474 | 0.230 | 4354.8 |
| 10 s | Go otimizado | 0.276 | 0.496 | 0.511 | 0.612 | 0.280 | 3569.4 |
| 15 s | PHP buildspc | 15.981 | 18.580 | 19.829 | 20.762 | 14.080 | 71.0 |
| 15 s | PHP otimizado | 9.505 | 11.449 | 12.054 | 13.993 | 8.612 | 116.1 |
| 15 s | C buildspc | 0.788 | 0.942 | 0.973 | 1.004 | 0.728 | 1373.9 |
| 15 s | C otimizado | 0.308 | 0.619 | 0.633 | 0.670 | 0.320 | 3128.6 |
| 15 s | Go otimizado | 0.383 | 0.722 | 0.747 | 0.779 | 0.399 | 2509.3 |

RSS inicial/pico/final dos processos no fim da série DSP (15 s), em KiB:
PHP buildspc 27.364/27.364/27.364; PHP otimizado
35.720/35.720/35.720; C buildspc 27.404/27.404/27.404;
C otimizado 35.464/35.464/35.464; Go 11.220/11.220/11.220.
O número de alocações por job da chamada PHP/C não foi instrumentado;
o benchmark Go registrou 0 alocações por operação.

Ganho relativo de mediana, sempre com PCM idêntico. PHP/C otimizados
compartilham o mesmo runtime; a comparação com `C buildspc` é outra build.

| Duração | C opt / PHP opt | Go / PHP opt | C opt / Go | Go / C buildspc |
|---:|---:|---:|---:|---:|
| 1 s | 19.03× | 15.65× | 1.22× | 1.46× |
| 3 s | 21.45× | 18.03× | 1.19× | 1.65× |
| 5 s | 23.84× | 20.04× | 1.19× | 1.76× |
| 10 s | 28.55× | 23.25× | 1.23× | 1.94× |
| 15 s | 30.81× | 24.81× | 1.24× | 2.06× |

## K. Concorrência C/Go

Os CPUs 4,6,8,10 são quatro núcleos P físicos distintos. C usa um PHP
por worker; Go usa um processo com GOMAXPROCS igual ao número de workers.
A tabela é de PCM 15 s, 1.000 jobs por configuração. CPU total é a soma
de tempo de CPU dividida pelo wall time; RSS C soma os processos PHP.

| Impl. | Workers / cores | jobs/s | CPU total / por core | p50 / p95 / p99 ms | RSS pico |
|---|---:|---:|---:|---|---:|
| C opt | 1 | 3128.6 | 100% / 100% | 0.308 / 0.619 / 0.633 | 34.6 MiB |
| Go | 1 | 2509.3 | 100% / 100% | 0.383 / 0.722 / 0.747 | 11.0 MiB |
| C opt | 2 | 6147.5 | 200% / 100% | 0.313 / 0.633 / 0.641 | 69.1 MiB |
| Go | 2 | 5019.0 | 200% / 100% | 0.383 / 0.723 / 0.739 | 10.6 MiB |
| C opt | 4 | 11467.2 | 372% / 93% | 0.312 / 0.632 / 0.638 | 135.4 MiB |
| Go | 4 | 9859.2 | 398% / 99% | 0.392 / 0.737 / 0.753 | 10.9 MiB |

Não houve crossover C otimizado/Go em 1–15 s ou 1/2/4 núcleos P:
C `-O2` venceu Go no throughput e mediana. Go venceu o C da build de
integração `-Os -g` em todas as durações. Go usou menos RSS no teste
concorrente, mas a comparação é um processo Go versus 2/4 processos PHP.

O worker real está fixado nos núcleos E 14/15. Uma repetição do DSP no núcleo
14, com 1.000 jobs por duração, confirmou a ordenação. Medianas em ms:

| PCM | C buildspc | C `-O2` | Go | C `-O2` / Go |
|---:|---:|---:|---:|---:|
| 1 s | 0,090 | 0,064 | 0,076 | 1,19× |
| 3 s | 0,242 | 0,152 | 0,186 | 1,22× |
| 5 s | 0,362 | 0,211 | 0,267 | 1,26× |
| 10 s | 0,655 | 0,349 | 0,451 | 1,29× |
| 15 s | 0,961 | 0,485 | 0,635 | 1,31× |

## L/M/N. SipSwoole sob RTP

### Pool global original: cinco geradores × 40 RTP calls

Mesmo script `bench/mailbox-global-stress.sh`, 2 workers, fila 32 e timeout
600 ms. `PHP atual` força `EarlyGreetingAnalysis`; `C nativo` força
`PCMAnalyzer`. Overload/timeout são deltas dos snapshots do pool; o
contador de clientes concluídos é o resultado recebido dentro do prazo.
CPU/RSS são somas dos cinco geradores RTP, sem o daemon; p95 é o maior
p95 entre geradores e inclui rejeições rápidas.

| Impl. | Submitted | Completed | Overload | Timeout | analysis p95 max | CPU geradores | RSS pico | loss RTP | dropped |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| PHP atual | 100 | 4 | 94 | 2 | 544.2 ms | 28.8 s | 257.1 MiB | 0 | 3094 |
| PHP atual | 250 | 100 | 150 | 0 | 500.6 ms | 17.4 s | 264.9 MiB | 0 | 2112 |
| PHP atual | 500 | 199 | 300 | 0 | 503.6 ms | 18.6 s | 271.4 MiB | 2034 | 10257 |
| C nativo | 100 | 22 | 76 | 0 | 601.5 ms | 27.0 s | 254.2 MiB | 1536 | 6059 |
| C nativo | 250 | 100 | 150 | 0 | 485.4 ms | 19.6 s | 261.6 MiB | 1536 | 6178 |
| C nativo | 500 | 200 | 300 | 0 | 475.4 ms | 19.0 s | 265.1 MiB | 2032 | 6253 |

No cenário antigo reportado havia 6/100 conclusões. Na repetição atual o PHP
teve 4/100 e o C 22/100; a melhoria de 18 jobs é real nesta rodada,
mas 78 ainda falharam abertos. A estimativa/admissão do pool e sobretudo o
DSP de ring (~150–235 ms/job) limitam o sistema. No nível 250/500, os
recebidos no prazo foram praticamente iguais. Com 100 finalizações, o
RTP registrou 0 loss/3.094 drops em PHP e 1.536 loss/6.059 drops em C;
uma rodada não permite atribuir causalidade, e não foi observado ganho RTP.

### Carga sustentada em um processo com 200 RTP calls

Duas submissões de análise em voo; esta modalidade testa throughput sustentado,
não o burst antigo. CPU worker vem de `/proc/<pid>/schedstat`.

| Impl. | Submitted | Completed | Overload | Timeout | p50/p95 análise | CPU worker | RSS pico gerador | dropped |
|---|---:|---:|---:|---:|---|---:|---:|---:|
| PHP atual | 100 | 100 | 0 | 0 | 254.2/263.7 ms | 24.6 s | 138.7 MiB | 0 |
| PHP atual | 250 | 249 | 0 | 0 | 254.0/263.7 ms | 61.3 s | 152.7 MiB | 6 |
| PHP atual | 500 | 499 | 0 | 0 | 253.9/263.3 ms | 122.9 s | 159.9 MiB | 0 |
| C nativo | 100 | 100 | 0 | 0 | 241.2/249.3 ms | 23.4 s | 160.4 MiB | 342 |
| C nativo | 250 | 250 | 0 | 0 | 240.9/251.5 ms | 58.7 s | 161.9 MiB | 0 |
| C nativo | 500 | 496 | 0 | 0 | 241.4/252.6 ms | 116.5 s | 165.1 MiB | 213 |

### Burst simultâneo em um processo com 200 RTP calls

Todas as finalizações tentam entrar de uma vez. P95 inclui overloads
rápidos; 100 jobs é o cenário de gargalo.

| Impl. | Submitted | Completed cliente | Completed pool | Overload | Timeout | p95 análise | loss RTP | dropped |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| PHP atual | 100 | 4 | 4 | 71 | 2 | 274.2 ms | 0 | 0 |
| PHP atual | 250 | 4 | 4 | 199 | 0 | 41.0 ms | 0 | 0 |
| PHP atual | 500 | 4 | 4 | 474 | 0 | 32.4 ms | 0 | 0 |
| C nativo | 100 | 4 | 6 | 28 | 0 | 261.6 ms | 0 | 0 |
| C nativo | 250 | 4 | 4 | 70 | 0 | 33.1 ms | 0 | 0 |
| C nativo | 500 | 4 | 4 | 496 | 0 | 30.3 ms | 0 | 0 |

Na modalidade simultânea, ambos entregaram 4/100 ao cliente; C
processou 6/100 no pool, mas dois terminaram fora do prazo do cliente.
O contrato fail-open foi preservado. Os testes de stress registraram fila
final zero e delta de descritores zero. O harness RTP não gera SIP; a sonda
E2E abaixo mediu ACK simultaneamente em outro processo.

RSS do processo gerador no teste de carga sustentada (MiB):

| Impl. | Jobs | Inicial | Pico | Final | Delta final |
|---|---:|---:|---:|---:|---:|
| PHP atual | 100 | 23,65 | 138,74 | 70,35 | +46,70 |
| PHP atual | 250 | 70,35 | 152,66 | 84,01 | +13,66 |
| PHP atual | 500 | 84,01 | 159,89 | 91,81 | +7,80 |
| C nativo | 100 | 23,64 | 160,41 | 81,33 | +57,69 |
| C nativo | 250 | 81,33 | 161,88 | 92,13 | +10,80 |
| C nativo | 500 | 92,13 | 165,11 | 97,44 | +5,31 |

O RSS final não volta ao inicial porque os processos PHP e as 200 sessões RTP
retêm arenas/alocações do teste. Os deltas por rodada diminuíram, mas três
rodadas não provam estabilidade de longo prazo do sistema; a extensão isolada
foi testada separadamente por 100.000 chamadas.

## Regressão SipSwoole e ACK

Passaram: `MailboxAcousticPolicyTest`, `MailboxAnalyzerTest` (32),
`MailboxPolicyV2Test`, `MailboxRobustnessTest`, `MailboxRtpIntegrationTest`
(44), `MailboxDatasetEvaluation`, `MailboxGlobalPoolTest`,
`MailboxNativeEquivalenceTest` (30), `MailboxNativeFailOpenTest` e
`MailboxDispatcherPoolProcessTest`, `MailboxOfflineToolTest`,
`MailboxPanelApiTest`, `MailboxBillingIntegrationTest` (10/10) e
`MailboxEndToEndTest` (42 cenários de matriz). O E2E verificou política,
ACK antes de BYE, dois BYEs quando cabíveis, CDR/billing e limpeza. Nas 49
chamadas `enforce` do E2E **sem o stress de 200 RTP calls**, B 200 OK → B ACK
foi p50 7,16 ms, p95 8,69 ms, p99/máximo 9,226 ms. O primeiro E2E foi
interrompido por timeout de teste de 180 s; a execução completa com limite
600 s passou.

Uma sonda E2E curta executou três chamadas SIP completas durante cada
rodada de 200 sessões RTP + 100/250/500 finalizações. Os processos da
sonda e do gerador RTP são distintos no mesmo host. Todos os 18 ACKs foram
observados antes do teardown. Milissegundos de B 200 OK até B ACK:

| Finalizações | Impl. | ACKs | p50 | máximo | Finalizações no prazo |
|---:|---|---:|---:|---:|---:|
| 100 | PHP | 3 | 2,121 | 2,694 | 4/100 |
| 100 | C | 3 | 2,899 | 3,025 | 4/100 |
| 250 | PHP | 3 | 2,355 | 3,221 | 4/250 |
| 250 | C | 3 | 2,609 | 2,622 | 4/250 |
| 500 | PHP | 3 | 2,690 | 3,026 | 4/500 |
| 500 | C | 3 | 1,820 | 3,111 | 4/500 |

Com três valores por célula, p95/p99 cairiam sobre a mediana pelo método de
percentil usado; os 18 valores individuais estão em `ack-under-stress.json`.
Essa sonda comprova a ordem ACK/teardown sob concorrência no host, mas não
estima percentis de produção nem exercita o ACK no mesmo processo dos 200
geradores RTP.

O binário de teste que inclui Opus/Redis/bcg729 foi produzido por um build SPC
estendido: o CLI compilou e executou, mas o comando SPC terminou com erro de
empacotamento porque o download de Opus não contém arquivo de licença. O
`buildspc.sh` obrigatório, sem esse pacote adicional, concluiu normalmente.

## O. Limitações e decisão arquitetural

1. A migração acelerou fortemente o *extrator de saudação*, mas o E2E de 15 s
   caiu apenas de cerca de 169 ms para 151 ms em medição isolada; o DSP de ring
   em PHP consome a maior parte do job. Em carga sustentada no pool, p50 foi
   cerca de 254 ms em PHP e 241 ms com C. Não há prova de que o gargalo esteja
   resolvido: no stress global atual, 78/100 finalizações ainda foram fail-open
   com C, embora PHP tivesse 96/100.
2. C `-O2` venceu Go padrão em 1–15 s e 1/2/4 núcleos P; Go venceu C do
   `buildspc.sh` (`-Os -g`) em 1–15 s. Não apareceu crossover nesse domínio.
   A tabela adicional de núcleo E preserva essa ordenação. As medições de
   stress cobrem os núcleos E e o sistema real.
3. O C otimizado foi medido em PHP 8.5 NTS dinâmico; o build real é PHP ZTS
   estático. Use a tabela do `buildspc.sh` para latência de integração, e a
   tabela `-O2`/Go para a comparação de implementação. RSS Go é menor no
   benchmark concorrente; a diferença inclui o custo de múltiplos processos
   PHP/Zend. Clock dinâmico e variação térmica não foram neutralizados.
4. Uma rodada global registrou mais perda e descarte RTP com C do que com PHP.
   Sem repetições estatísticas e ambiente separado para tráfego, não é correto
   atribuir essa diferença ao extrator. A sonda ACK sob stress tem só três
   chamadas por cenário e roda em outro processo; ela não representa uma
   distribuição robusta do servidor SIP sob carga.
5. O segmento patológico acima de 64 regiões gera `ValueError` e fail-open,
   por desenho. A API inicial só suporta PCM16LE mono 8 kHz/20 ms. O conjunto
   sintético não substitui áudio real rotulado, e a política existente mantém
   falsos positivos conhecidos para avisos de operadora/URA.

A arquitetura nativa é simples porque `psampler` já era dependência, passou
na equivalência e economiza CPU. Manter o pool, o switch PHP/nativo e o
fail-open é necessário. A próxima redução relevante da fila exigirá medir e
otimizar a análise de ring/estimativa de serviço no lado SipSwoole sem mover
política de telefonia para C.
