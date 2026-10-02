# Fechamento da stream PCM — 2026-10-02

## Artefato validado

Build: `./buildspc.sh`. Binário produzido pelo script:
`/home/lotus/CLionProjects/pcg729/buildroot/bin/php`, copiado para
`/home/lotus/projetos/psampler/php`. Os dois arquivos têm SHA-256
`03179a91c6e9e7b3f5e6115ee62a846bc66854ed775726843d59378948566ff6`.
Todos os PHPT, testes PHP funcionais/de stress, benchmarks e perf finais abaixo
usaram `./php -n`, essa cópia exata.

`./php -n --version`:

```text
PHP 8.5.10 (cli) (built: Oct  2 2026 05:49:53) (ZTS)
Zend Engine v4.5.10
with Zend OPcache v8.5.10
```

`./php -n --ri psampler` informa `psampler support => enabled`, versão
`0.6.1`, API nativa v1 e cache FIR ZTS. `method_exists()` confirma
`PcmBuffer::reset` e `PcmBuffer::flush`. O build é CLI estático musl/GCC 13.2,
`--enable-zts --enable-psampler --no-strip`, com `-Os -g
-fstack-protector-strong -fPIE -fPIC -fno-ident -ffp-contract=off
-fno-math-errno`. O comando do SPC usa
`--build-cli "bcg729,swoole,ctype,standard,filter,psampler"`.

## Comportamento final

Downsample, upsample e conversões fracionárias usam o mesmo sinc/Kaiser de 64
taps e 256 fases. Cada objeto `PcmBuffer` mantém sua fase absoluta, as 32
amostras anteriores necessárias ao FIR e o estado DC por canal. O cache global
guarda apenas coeficientes imutáveis. `clear()` limpa o payload sem interromper
o DSP; o próximo `append()` de uma stream ativa restaura o formato de entrada.
`reset(rate, channels)` descarta o DSP anterior. `flush()` emite a cauda final,
é idempotente e encerra a stream até o próximo `reset()`. O total após flush
é `round(inputSamples * dstRate / srcRate)` por canal. `Resampler::sample()`
agora consome entradas maiores que 8192 samples e rejeita PCM16 ímpar.

Configurações DSP fora de 1000–768000 Hz, frames incompletos e saída estimada
acima de 64 MiB por chamada geram `ValueError` controlado. Uma falha de
configuração deixa os bytes e metadados do `PcmBuffer` intactos.

## Testes

- `./php -n run-tests.php -n -q tests`: 15/15 PHPT, sem skip/falha.
- `./php -n tests/pcm_stream.php`: 14 pares de taxas, mono e stereo; áudio
  inteiro e chunks de 5, 10, 20, 30 e 40 ms produzem bytes/hashes idênticos.
  Quatro streams intercaladas também igualam suas execuções isoladas. Entradas
  vazias e de 1–65 samples têm duração exata após flush.
- `./php -n tests/pcm_stream_stress.php`: 10 e 60 s nas seis conversões de
  drift exigidas, todas com contagem exata após flush. Mais 160 mil frames de
  80 streams com reset, flush e destruição; arena PHP 4,0→6,0 MiB, RSS
  20.772→23.168 KiB, com as últimas cinco rodadas estáveis em 23.168 KiB.
- `python3 tests/test_pcm_benchmark.py --php='./php -n' --go=./pcm_benchmark_go`
  e `python3 tests/test_pcm_standalone.py --php='./php -n'
  --original-php='./php -n' --c=./pcm_benchmark_c --go=./pcm_benchmark_go`:
  ambos passaram. A comparação de fixtures/downmix e a paridade C/Go foram
  mantidas; as assertions PHP agora exigem duração de stream contínua com
  latência final limitada, pois os benchmarks C/Go existentes reiniciam o
  filtro a cada frame.
- `GO111MODULE=off go test pcm_benchmark.go pcm_benchmark_test.go`,
  `GO111MODULE=off go test voice_benchmark.go voice_benchmark_test.go` e
  `GO111MODULE=off go test ./bench/pcmgo`: passaram. O comando `go test ./...`
  na raiz não é aplicável, pois ela mistura fontes C sem módulo Go.
- `./php -n test_new_api.php`, `./php -n test_lpcm.php`,
  `./php -n test_stream_simulation.php` (14 formatos WAV),
  `./php -n test_audio_analysis.php` e
  `./php -n tests/pcm_analyzer_stress.php`: concluíram sem erro.
- Builds de diagnóstico separados com `-fsanitize=address,undefined`:
  `tests/pcm_stream_asan.c` e `tests/pcm_cache_threads.c` passaram. O segundo
  exercitou oito threads, 40 pares de taxas, criação/evicção/liberação do
  cache. A revisão do código confirmou mutex para publicação, referências e
  evicção, e shutdown após as threads. ThreadSanitizer não iniciou neste
  ambiente (`unexpected memory mapping`), portanto não há resultado TSAN.

## Benchmark de regressão

Comando: `python3 bench/run-stream-regression.py`. Ele executa o benchmark
existente com `taskset -c 2 ./php -n pcm_benchmark.php --runtime=throughput
--calls=50 --frames=10000 --ptime=20 --source-channels=2
--target-channels=1`, variando as taxas. Cada linha mede 500.000 frames e
10.000 segundos lógicos de áudio. Baseline antigo: mediana de três medições
PHP anteriores, com o mesmo tamanho de carga. A repetição contemporânea com
o binário baseline antigo confirmou 5,653/5,385/6,379 s para as três taxas
críticas; ela serviu apenas como comparação, não como validação final.

| Taxa | Baseline (s) | Novo (s) | Δ tempo | Saída antiga → nova (MB) |
| --- | ---: | ---: | ---: | ---: |
| 8000 → 8000 | 0,214 | 0,232 | +8,8% | 160,0 → 160,0 |
| 16000 → 8000 | 4,408 | 4,887 | +10,9% | 144,0 → 160,0 |
| 24000 → 8000 | 4,732 | 5,135 | +8,5% | 149,0 → 160,0 |
| 32000 → 8000 | 4,995 | 5,338 | +6,9% | 152,0 → 160,0 |
| 44100 → 8000 | 5,580 | 5,709 | +2,3% | 154,0 → 160,0 |
| 48000 → 8000 | 5,394 | 5,607 | +3,9% | 154,0 → 160,0 |
| 96000 → 8000 | 6,395 | 6,478 | +1,3% | 157,0 → 160,0 |
| 8000 → 16000 | — | 9,286 | — | — → 320,0 |
| 8000 → 32000 | — | 18,308 | — | — → 640,0 |
| 8000 → 44100 | — | 26,424 | — | — → 882,0 |
| 8000 → 48000 | — | 27,142 | — | — → 960,0 |
| 44100 → 48000 | — | 29,288 | — | — → 960,0 |
| 48000 → 44100 | — | 26,907 | — | — → 882,0 |

A variação de CPU nas taxas menores acompanha os samples adicionais corretos:
em 16000→8000, a saída cresce 11,1% e o tempo 10,9%. Nas taxas críticas o
tempo cresce 1,3–3,9%, enquanto a saída cresce 1,9–3,9%. A linha de bypass
difere 0,018 s absolutos em 500.000 frames. Não há regressão relevante por
sample produzido. [benchmark.json](../bench/results/pcm-stream-2026-10-02/benchmark.json)
registra por taxa elapsed, frames/s, CPU user/system/total, áudio/s de parede,
memória inicial/pico/final, bytes de saída e hashes.

## Perf final

Comandos: `taskset -c 2 ./perf_pcm_benchmark.sh --language=php
--php-bin=./php --source-rate=RATE --target-rate=TARGET --calls=50
--frames=10000 --event=cpu_core/cycles/`, para 48000→8000, 44100→8000 e
8000→48000. Foram coletadas aproximadamente 5K, 5K e 27K amostras no
evento dominante. Hotspots:

| Taxa | FIR | Downmix | Outros |
| --- | ---: | ---: | ---: |
| 48000 → 8000 | 85,76% | 11,31% | 2,93% |
| 44100 → 8000 | 86,27% | 10,19% | 3,54% |
| 8000 → 48000 | 98,78% | 0,42% | 0,80% |

O perfil baseline 48000→8000 tinha 83,61% no FIR e 11,95% no downmix. Nos
novos perfis, `psampler_pcm_stream_process` fica até 0,23% e `memcpy` até
0,12%; não surgiram hotspots relevantes de malloc/free, memmove, mutex ou
Zend. Relatórios e comandos integrais estão em
[bench/results/pcm-stream-2026-10-02](../bench/results/pcm-stream-2026-10-02/).
